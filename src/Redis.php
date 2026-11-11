<?php

declare(strict_types=1);

namespace Specrig;

final class Redis implements Resource
{
    /** @var array<string, array{string, Gate}> */
    private static array $servers = [];

    private ?string $server = null;

    private ?Gate $gate = null;

    private bool $refusing = false;

    private bool $booted = false;

    public function __construct(
        private readonly string $setting,
        private readonly ?string $address = null,
        private readonly string $image = 'redis:8-alpine',
    ) {
    }

    public function setting(): string
    {
        return $this->setting;
    }

    public function attach(Trace $trace, \Closure $boot): void
    {
    }

    // A fault a step sets before the boot waits for it: the service boots with its Redis up.
    public function failWith(): void
    {
        $this->refusing = true;
        if ($this->booted) {
            $this->gate?->refuse();
        }
    }

    // The service gets the address of a gate, so that a fault cuts the clients it already built.
    public function provision(string $service): string
    {
        $name = preg_replace('/[^A-Z0-9]/', '_', strtoupper($service));
        $given = $this->address ?? Environment::get("{$name}_TEST_REDIS");
        [$this->server, $this->gate] = self::$servers[$given ?? $this->image] ??= self::start($name, $given, $this->image);
        $parts = parse_url($this->server);
        $credentials = isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ":{$parts['pass']}" : '') . '@' : '';
        return ($parts['scheme'] ?? 'redis') . "://{$credentials}127.0.0.1:{$this->gate->port}" . ($parts['path'] ?? '') . (isset($parts['query']) ? "?{$parts['query']}" : '');
    }

    public function ready(Rig $rig): void
    {
        $this->command('FLUSHDB');
        $this->booted = true;
        if ($this->refusing) {
            $this->gate?->refuse();
        }
    }

    public function failures(): array
    {
        return [];
    }

    public function release(): void
    {
        $this->gate?->reopen();
        $this->refusing = false;
        $this->booted = false;
    }

    /** @return array{string, Gate} */
    private static function start(string $service, ?string $given, string $image): array
    {
        $address = $given ?? self::container($service, $image);
        $parts = parse_url($address);
        return [$address, Gate::start(($parts['host'] ?? '127.0.0.1') . ':' . ($parts['port'] ?? 6379))];
    }

    // Starting redis costs less than a second, so the container is the run's own rather than kept.
    private static function container(string $service, string $image): string
    {
        $docker = new Docker();
        try {
            $id = $docker->run($image, 6379, [], ['specrig' => 'redis']);
            Rig::ending(fn () => $docker->remove($id));
            $address = 'redis://' . Docker::host() . ':' . $docker->port($id, 6379);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("redis: no address is given and no container started — set {$service}_TEST_REDIS, or start Docker: {$e->getMessage()}", 0, $e);
        }
        $deadline = microtime(true) + 30;
        while (true) {
            try {
                self::send($address, 'PING');
                return $address;
            } catch (\RuntimeException $e) {
                if (microtime(true) > $deadline) {
                    throw $e;
                }
                usleep(100_000);
            }
        }
    }

    private function command(string ...$command): string
    {
        return self::send($this->server ?? throw new \LogicException('redis is not provisioned: the service has not booted'), ...$command);
    }

    private static function send(string $address, string ...$command): string
    {
        $parts = parse_url($address);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 6379;
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $code, $message, 5);
        if ($socket === false) {
            throw new \RuntimeException("redis at {$host}:{$port}: {$message}");
        }
        try {
            $commands = [];
            if (isset($parts['pass']) || isset($parts['user'])) {
                $commands[] = isset($parts['user']) && $parts['user'] !== '' ? ['AUTH', rawurldecode($parts['user']), rawurldecode($parts['pass'] ?? '')] : ['AUTH', rawurldecode($parts['pass'] ?? '')];
            }
            $database = trim($parts['path'] ?? '', '/');
            if ($database !== '' && $database !== '0') {
                $commands[] = ['SELECT', $database];
            }
            $commands[] = $command;
            $reply = '';
            foreach ($commands as $words) {
                fwrite($socket, '*' . count($words) . "\r\n" . implode('', array_map(fn (string $w) => '$' . strlen($w) . "\r\n{$w}\r\n", $words)));
                $reply = (string) fgets($socket);
                if (!str_starts_with($reply, '+')) {
                    throw new \RuntimeException("redis at {$host}:{$port}: " . trim($reply ?: 'no reply'));
                }
            }
            return trim(substr($reply, 1));
        } finally {
            fclose($socket);
        }
    }
}
