<?php

declare(strict_types=1);

namespace Specrig;

final class Gate
{
    private bool $refusing = false;

    /**
     * @param resource $process
     * @param resource $commands
     * @param resource $answers
     */
    private function __construct(
        private $process,
        private $commands,
        private $answers,
        public readonly int $port,
    ) {
    }

    public static function start(string $upstream): self
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/Gate/serve.php', $upstream], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        $port = is_resource($process) ? (int) trim((string) fgets($pipes[1])) : 0;
        if ($port === 0) {
            throw new \RuntimeException("the gate to {$upstream} did not start");
        }
        $gate = new self($process, $pipes[0], $pipes[1], $port);
        Rig::ending(fn () => $gate->stop());
        return $gate;
    }

    public function refuse(): void
    {
        $this->send('refuse', true);
    }

    public function reopen(): void
    {
        $this->send('reopen', false);
    }

    private function send(string $command, bool $refusing): void
    {
        if ($this->refusing === $refusing) {
            return;
        }
        if (fwrite($this->commands, "{$command}\n") === false || fgets($this->answers) === false) {
            throw new \RuntimeException("the gate did not answer {$command}");
        }
        $this->refusing = $refusing;
    }

    private function stop(): void
    {
        fclose($this->commands);
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
