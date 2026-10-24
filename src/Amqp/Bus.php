<?php

declare(strict_types=1);

namespace Specrig\Amqp;

use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Specrig\Json;
use Specrig\Resource;
use Specrig\Rig;
use Specrig\Trace;

final class Bus implements Resource
{
    /** @var array<string, string> */
    private array $exchanges = [];

    /** @var array<string, true> */
    private array $queues = [];

    /** @var list<array{queue: string, exchange: string, key: string}> */
    private array $bindings = [];

    /** @var array<string, array{queue: string, ack: bool, callback: callable, channel: Channel}> */
    private array $consumers = [];

    /** @var list<Message> */
    private array $published = [];

    /** @var array<int, array{key: string, settled: ?Settled}> */
    private array $deliveries = [];

    private int $tags = 0;

    private Trace $trace;

    private \Closure $boot;

    private ?\Throwable $fault = null;

    private ?Connection $connection = null;

    public function __construct(private readonly string $setting, private readonly string $address = 'amqp://guest:guest@rabbit.harness.invalid:5672/')
    {
        $this->trace = new Trace();
        $this->boot = static fn () => null;
    }

    public function setting(): string
    {
        return $this->setting;
    }

    public function attach(Trace $trace, \Closure $boot): void
    {
        $this->trace = $trace;
        $this->boot = $boot;
    }

    public function provision(string $service): string
    {
        return $this->address;
    }

    public function ready(Rig $rig): void
    {
    }

    public function failures(): array
    {
        $left = array_filter($this->deliveries, fn (array $d) => $d['settled'] === null);
        return array_values(array_map(fn (array $d) => "a delivery of {$d['key']} its consumer left unsettled", $left));
    }

    public function release(): void
    {
    }

    public function connection(): Connection
    {
        return $this->connection ??= new Connection($this);
    }

    public function failWith(?\Throwable $error = null): void
    {
        $this->fault = $error ?? new AMQPConnectionClosedException('CONNECTION_FORCED - the broker closed the connection', 320);
    }

    /** @return list<Message> */
    public function published(?string $routingKey = null): array
    {
        return array_values(array_filter($this->published, fn (Message $m) => $routingKey === null || $m->routingKey === $routingKey));
    }

    /**
     * @param array<string, mixed> $headers
     * @return list<Settled>
     */
    public function deliver(string $routingKey, mixed $body, ?string $exchange = null, array $headers = []): array
    {
        $bytes = match (true) {
            $body === null => '',
            is_string($body) => $body,
            default => Json::encode($body),
        };
        ($this->boot)();
        $targets = $this->route($routingKey, $exchange);
        $call = $this->trace->begin();
        $settled = [];
        try {
            foreach ($targets as [$tag, $from]) {
                $consumer = $this->consumers[$tag];
                $delivery = ++$this->tags;
                $this->deliveries[$delivery] = ['key' => $routingKey, 'settled' => $consumer['ack'] ? Settled::Acked : null];
                $message = new AMQPMessage($bytes, ['content_type' => 'application/json'] + ($headers === [] ? [] : ['application_headers' => new AMQPTable($headers)]));
                $message->setChannel($consumer['channel']);
                $message->setDeliveryInfo($delivery, false, $from, $routingKey);
                $message->setConsumerTag($tag);
                ($consumer['callback'])($message);
                $settled[] = $this->deliveries[$delivery]['settled'] ?? Settled::Unsettled;
            }
            return $settled;
        } finally {
            $refused = array_values(array_filter($settled, fn (Settled $s) => $s !== Settled::Acked))[0] ?? null;
            $this->trace->end($call, 'in', 'event', $routingKey, Json::decode($bytes), $refused?->value);
        }
    }

    public function declared(string $exchange, string $type): void
    {
        $this->exchanges[$exchange] = $type;
    }

    /** @return array{string, int, int} */
    public function queue(string $queue): array
    {
        $queue = $queue === '' ? 'amq.gen-' . bin2hex(random_bytes(8)) : $queue;
        $this->queues[$queue] = true;
        return [$queue, 0, count(array_filter($this->consumers, fn (array $c) => $c['queue'] === $queue))];
    }

    public function bound(string $queue, string $exchange, string $key): void
    {
        $this->bindings[] = ['queue' => $queue, 'exchange' => $exchange, 'key' => $key];
    }

    public function unbound(string $queue, string $exchange, string $key): void
    {
        $this->bindings = array_values(array_filter($this->bindings, fn (array $b) => $b !== ['queue' => $queue, 'exchange' => $exchange, 'key' => $key]));
    }

    public function consume(Channel $channel, string $queue, string $tag, bool $ack, ?callable $callback): string
    {
        $tag = $tag === '' ? 'amq.ctag-' . bin2hex(random_bytes(8)) : $tag;
        $this->consumers[$tag] = ['queue' => $queue, 'ack' => $ack, 'callback' => $callback ?? static fn () => null, 'channel' => $channel];
        return $tag;
    }

    public function cancel(string $tag): void
    {
        unset($this->consumers[$tag]);
    }

    /** @param array<string, mixed> $headers */
    public function publish(string $exchange, string $key, array $headers, string $body): void
    {
        if ($this->fault !== null) {
            throw $this->fault;
        }
        $message = new Message($exchange, $key, $headers, $body);
        $call = $this->trace->begin();
        $this->published[] = $message;
        $this->trace->end($call, 'out', 'event', $key, $message->json());
    }

    public function settle(int $tag, bool $multiple, Settled $how): void
    {
        foreach ($this->deliveries as $delivery => $state) {
            if (($delivery === $tag || $multiple && $delivery < $tag) && $state['settled'] === null) {
                $this->deliveries[$delivery]['settled'] = $how;
            }
        }
    }

    /** @return list<array{string, string}> */
    private function route(string $key, ?string $exchange): array
    {
        $matched = array_values(array_filter($this->bindings, fn (array $b) => ($exchange === null || $b['exchange'] === $exchange) && $this->takes($b, $key)));
        if (($exchange === null || $exchange === '') && isset($this->queues[$key])) {
            $matched[] = ['queue' => $key, 'exchange' => '', 'key' => $key];
        }
        $exchanges = array_values(array_unique(array_column($matched, 'exchange')));
        if ($exchange === null && count($exchanges) > 1) {
            throw new \LogicException("{$key} is bound on " . implode(' and ', $exchanges) . ': name the exchange to deliver it through');
        }
        if ($matched === []) {
            $bound = $this->bindings === [] ? 'nothing' : implode(', ', array_map(fn (array $b) => "{$b['exchange']}:{$b['key']} → {$b['queue']}", $this->bindings));
            throw new \LogicException("no queue of the service binds {$key}" . ($exchange === null ? '' : " on {$exchange}") . "; it binds {$bound}");
        }
        $targets = [];
        $idle = [];
        foreach (array_unique(array_column($matched, 'queue')) as $queue) {
            $tags = array_keys(array_filter($this->consumers, fn (array $c) => $c['queue'] === $queue));
            if ($tags === []) {
                $idle[] = $queue;
                continue;
            }
            $from = array_values(array_filter($matched, fn (array $b) => $b['queue'] === $queue))[0]['exchange'];
            $targets[] = [(string) $tags[0], $from];
        }
        if ($idle !== []) {
            throw new \LogicException(implode(', ', $idle) . " takes {$key}, and nothing consumes from it: is its command in running()?");
        }
        return $targets;
    }

    /** @param array{queue: string, exchange: string, key: string} $binding */
    private function takes(array $binding, string $key): bool
    {
        return match ($this->exchanges[$binding['exchange']] ?? 'topic') {
            'fanout' => true,
            'direct' => $binding['key'] === $key,
            'headers' => throw new \LogicException("{$binding['exchange']} is a headers exchange, which the bus stand-in does not route"),
            default => self::topic(explode('.', $binding['key']), explode('.', $key)),
        };
    }

    /**
     * @param list<string> $pattern
     * @param list<string> $words
     */
    private static function topic(array $pattern, array $words): bool
    {
        if ($pattern === []) {
            return $words === [];
        }
        if ($pattern[0] === '#') {
            for ($skip = 0; $skip <= count($words); $skip++) {
                if (self::topic(array_slice($pattern, 1), array_slice($words, $skip))) {
                    return true;
                }
            }
            return false;
        }
        return $words !== [] && ($pattern[0] === '*' || $pattern[0] === $words[0]) && self::topic(array_slice($pattern, 1), array_slice($words, 1));
    }
}
