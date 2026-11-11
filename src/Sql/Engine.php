<?php

declare(strict_types=1);

namespace Specrig\Sql;

use Specrig\Write;

abstract class Engine
{
    private \Throwable|false|null $migration = null;

    private ?\PDO $connection = null;

    /** @var list<string> */
    protected array $tables = [];

    protected function __construct(public readonly string $address)
    {
    }

    /**
     * @param \Closure(): void $migrate
     * @param list<string> $keep
     */
    public function once(\Closure $migrate, array $keep): void
    {
        if ($this->migration === null) {
            try {
                $migrate();
                $this->migration = false;
                $this->remember($keep);
                $this->reset();
            } catch (\Throwable $e) {
                $this->migration = $e;
            }
        }
        if ($this->migration instanceof \Throwable) {
            throw new \RuntimeException("{$this->name()} did not migrate: {$this->migration->getMessage()}", 0, $this->migration);
        }
    }

    public function reset(): void
    {
        $this->lift();
        if ($this->tables !== []) {
            $this->restore();
        }
    }

    /** @param list<Write> $writes */
    abstract public function refuse(string $table, int $rows, array $writes): void;

    abstract public function lift(): void;

    abstract public function refusing(): string;

    abstract protected function name(): string;

    /** @param list<string> $keep */
    abstract protected function remember(array $keep): void;

    abstract protected function restore(): void;

    protected function connection(): \PDO
    {
        return $this->connection ??= Address::parse($this->address)->pdo();
    }

    protected function close(): void
    {
        $this->connection = null;
    }

    /**
     * @param list<Write> $writes
     * @return list<string>
     */
    protected static function events(array $writes): array
    {
        return array_values(array_map(fn (Write $write) => $write->value, array_filter(Write::cases(), fn (Write $write) => $writes === [] || in_array($write, $writes, true))));
    }
}
