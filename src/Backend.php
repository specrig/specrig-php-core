<?php

declare(strict_types=1);

namespace Specrig;

use Specrig\Http\Origin;
use Specrig\Http\Request;
use Specrig\Http\Response;
use Specrig\Http\Unreachable;

final class Backend implements StandIn
{
    private readonly string $origin;

    private string $name = '';

    private Trace $trace;

    private ?Contract $checked = null;

    /** @var array<string, list<array{array<string, list<string>>, int, mixed}>> */
    private array $replies = [];

    /** @var array<string, list<array<string, list<string>>>> */
    private array $timeouts = [];

    /** @var list<Received> */
    private array $received = [];

    /** @var list<string> */
    private array $unanswered = [];

    /** @var list<string> */
    private array $violations = [];

    public function __construct(
        string $origin,
        private readonly ?string $setting = null,
        private readonly ?string $contract = null,
        private readonly ?string $failure = null,
    ) {
        $this->origin = Origin::of($origin);
        $this->trace = new Trace();
    }

    public function origin(): string
    {
        return $this->origin;
    }

    public function setting(): ?string
    {
        return $this->setting;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function attach(string $name, Trace $trace, string $root): void
    {
        $contract = match (true) {
            $this->contract === null => null,
            str_starts_with($this->contract, '/') => Contract::load($this->contract, $name, $this->failure),
            default => Contract::load("{$root}/{$this->contract}", $name, $this->failure),
        };
        $this->bind($name, $trace, $contract);
    }

    public function bind(string $name, Trace $trace, ?Contract $contract): void
    {
        $this->name = $name;
        $this->trace = $trace;
        $this->checked = $contract;
    }

    public function failures(): array
    {
        $failures = [];
        if ($this->unanswered !== []) {
            $failures[] = "{$this->name} was called where no step declared its answer: " . implode(', ', $this->unanswered);
        }
        if ($this->violations !== []) {
            $failures[] = "what {$this->name}'s contract, {$this->contract}, does not allow: " . implode('; ', $this->violations);
        }
        return $failures;
    }

    public function reply(string $method, string $path, int $status, mixed $body = null): void
    {
        $this->replyTo($method, $path, [], $status, $body);
    }

    /** @param array<string, string|list<string>> $query */
    public function replyTo(string $method, string $path, array $query, int $status, mixed $body = null): void
    {
        $key = "{$method} {$path}";
        $query = self::listed($query);
        $this->replies[$key] = [
            ...array_values(array_filter($this->replies[$key] ?? [], fn (array $r) => !self::same($r[0], $query))),
            [$query, $status, Json::of($body)],
        ];
    }

    /** @param array<string, string|list<string>> $query */
    public function timeOut(string $method, string $path, array $query = []): void
    {
        $key = "{$method} {$path}";
        $query = self::listed($query);
        $this->timeouts[$key] = [...array_values(array_filter($this->timeouts[$key] ?? [], fn (array $q) => !self::same($q, $query))), $query];
    }

    /** @return list<Received> */
    public function requests(string $method, string $path): array
    {
        return array_values(array_filter($this->received, fn (Received $r) => $r->method === $method && $r->path === $path));
    }

    /** @return list<string> */
    public function unanswered(): array
    {
        return $this->unanswered;
    }

    /** @return list<string> */
    public function violations(): array
    {
        return $this->violations;
    }

    public function answer(Request $request): Response
    {
        $method = strtoupper($request->method);
        $path = $request->path();
        $key = "{$method} {$path}";
        $query = self::parse($request->search());
        $body = Json::decode($request->body);
        $call = $this->trace->begin();
        try {
            $this->received[] = new Received($method, $path, $query, $body);
            array_push($this->violations, ...$this->checked?->request($method, $path, $body) ?? []);
            if (self::pick($this->timeouts[$key] ?? [], $query, fn (array $q) => $q) !== null) {
                throw new Unreachable("{$this->name} did not answer {$key} in time", timedOut: true);
            }
            return $this->fulfil($method, $path, $key . $request->search(), $query);
        } finally {
            $this->trace->end($call, 'out', 'http', $this->checked?->name($method, $path) ?? $key, $body);
        }
    }

    /** @param array<string, list<string>> $query */
    private function fulfil(string $method, string $path, string $asked, array $query): Response
    {
        $reply = self::pick($this->replies["{$method} {$path}"] ?? [], $query, fn (array $r) => $r[0]);
        if ($reply === null) {
            $this->unanswered[] = $asked;
            return self::respond(501, (object) ['code' => 'unanswered', 'message' => "{$method} {$path}"]);
        }
        [, $status, $body] = $reply;
        array_push($this->violations, ...$this->checked?->response($method, $path, $status, $body ?? new \stdClass()) ?? []);
        return self::respond($status, $status === 204 ? null : $body ?? new \stdClass());
    }

    private static function respond(int $status, mixed $body): Response
    {
        return $body === null
            ? new Response($status)
            : new Response($status, ['Content-Type' => 'application/json'], Json::encode($body));
    }

    /**
     * @template T
     * @param list<T> $candidates
     * @param array<string, list<string>> $query
     * @param \Closure(T): array<string, list<string>> $of
     * @return T|null
     */
    private static function pick(array $candidates, array $query, \Closure $of): mixed
    {
        $found = null;
        foreach ($candidates as $candidate) {
            if (self::matches($of($candidate), $query) && count($of($candidate)) >= ($found === null ? 0 : count($of($found)))) {
                $found = $candidate;
            }
        }
        return $found;
    }

    /**
     * @param array<string, list<string>> $wanted
     * @param array<string, list<string>> $got
     */
    private static function matches(array $wanted, array $got): bool
    {
        foreach ($wanted as $name => $values) {
            $have = $got[$name] ?? null;
            if ($have === null || (count($values) === 1 ? ($have[0] ?? null) !== $values[0] : $have !== $values)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, list<string>> $a
     * @param array<string, list<string>> $b
     */
    private static function same(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);
        return $a === $b;
    }

    /**
     * @param array<string, string|list<string>> $query
     * @return array<string, list<string>>
     */
    private static function listed(array $query): array
    {
        return array_map(fn ($v) => array_map('strval', is_array($v) ? array_values($v) : [$v]), $query);
    }

    /** @return array<string, list<string>> */
    public static function parse(string $search): array
    {
        $values = [];
        foreach (explode('&', ltrim($search, '?')) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $values[urldecode($name)][] = $value === null ? '' : urldecode($value);
        }
        return $values;
    }
}
