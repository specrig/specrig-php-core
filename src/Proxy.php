<?php

declare(strict_types=1);

namespace Specrig;

final class Proxy
{
    private const VARIABLES = ['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY'];

    private static ?self $running = null;

    /** @var resource */
    private $process;

    private int $offset = 0;

    private function __construct(private readonly string $log, private readonly string $address)
    {
    }

    public static function start(): self
    {
        if (self::$running !== null) {
            return self::$running;
        }
        $log = tempnam(sys_get_temp_dir(), 'specrig-proxy');
        $process = proc_open([PHP_BINARY, __DIR__ . '/Proxy/serve.php', $log], [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        $listening = is_resource($process) ? trim((string) fgets($pipes[1])) : '';
        if ($listening === '') {
            throw new \RuntimeException('the harness\'s proxy did not start');
        }
        $proxy = new self($log, "http://{$listening}");
        $proxy->process = $process;
        Rig::ending(fn () => $proxy->stop());
        return self::$running = $proxy;
    }

    /** @return array<string, string|null> */
    public function environment(): array
    {
        return array_fill_keys(self::VARIABLES, $this->address) + ['no_proxy' => null, 'NO_PROXY' => null];
    }

    public function mark(): void
    {
        clearstatcache(true, $this->log);
        $this->offset = (int) filesize($this->log);
    }

    /** @return list<string> */
    public function refused(): array
    {
        clearstatcache(true, $this->log);
        $lines = (string) file_get_contents($this->log, false, null, $this->offset);
        return array_map(self::named(...), array_values(array_filter(explode("\n", $lines))));
    }

    // A CONNECT names only the origin: the rest went inside the tunnel the proxy refused.
    private static function named(string $line): string
    {
        [$method, $target] = array_pad(explode(' ', $line), 2, '');
        if ($method === 'CONNECT') {
            return str_ends_with($target, ':443') ? 'https://' . substr($target, 0, -4) : "https://{$target}";
        }
        return "{$method} {$target}";
    }

    private function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->log);
        self::$running = null;
    }
}
