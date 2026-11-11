<?php

declare(strict_types=1);

namespace Specrig\Sqlite;

use Specrig\Rig;
use Specrig\Sql\Address;
use Specrig\Sql\Engine;

final class File extends Engine
{
    private const BASELINE = 'specrig_baseline';

    // In main beside the tables, because a trigger reads only its own schema.
    private const QUOTA = 'specrig_refusal';

    /** @var list<string> */
    private array $inserts = [];

    /** @var list<array{string, int}>|null null where no table counts with AUTOINCREMENT */
    private ?array $sequences = null;

    private function __construct(private readonly string $directory, private readonly string $path)
    {
        parent::__construct((string) Address::file($path));
    }

    public static function start(string $service): self
    {
        $directory = sys_get_temp_dir() . '/specrig-sqlite-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        touch("{$directory}/{$service}.db");
        $file = new self($directory, "{$directory}/{$service}.db");
        Rig::ending(fn () => $file->down());
        return $file;
    }

    public function refusing(): string
    {
        return (string) Address::file($this->directory);
    }

    public function refuse(string $table, int $rows, array $writes): void
    {
        $events = self::events($writes);
        $name = 'specrig_refuse_' . implode('_', $events);
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::QUOTA . ' (relation text, name text, remaining int NOT NULL, PRIMARY KEY (relation, name))');
            $connection->prepare('INSERT INTO ' . self::QUOTA . ' (relation, name, remaining) VALUES (?, ?, ?) ON CONFLICT (relation, name) DO UPDATE SET remaining = excluded.remaining')
                ->execute([$table, $name, $rows]);
            $quota = 'relation = ' . $connection->quote($table) . ' AND name = ' . $connection->quote($name);
            foreach ($events as $event) {
                $trigger = self::quoted("{$name}_{$event}_on_{$table}");
                $connection->exec("DROP TRIGGER IF EXISTS {$trigger}");
                $connection->exec("CREATE TRIGGER {$trigger} BEFORE {$event} ON " . self::quoted($table) . ' BEGIN '
                    . 'UPDATE ' . self::QUOTA . " SET remaining = remaining - 1 WHERE {$quota}; "
                    . 'SELECT RAISE(ABORT, ' . $connection->quote("specrig: {$event} on {$table} is refused") . ') '
                    . 'WHERE (SELECT remaining FROM ' . self::QUOTA . " WHERE {$quota}) < 0; "
                    . 'END');
            }
            $connection->commit();
        } catch (\PDOException $e) {
            $connection->rollBack();
            throw new \RuntimeException("cannot refuse writes to {$table}: {$e->getMessage()}", 0, $e);
        }
    }

    public function lift(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $triggers = $connection->query("SELECT name FROM sqlite_schema WHERE type = 'trigger' AND name LIKE 'specrig\\_refuse\\_%' ESCAPE '\\'");
            foreach ($triggers->fetchAll(\PDO::FETCH_COLUMN) as $trigger) {
                $connection->exec('DROP TRIGGER ' . self::quoted($trigger));
            }
            $connection->exec('DROP TABLE IF EXISTS ' . self::QUOTA);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    protected function name(): string
    {
        return $this->path;
    }

    protected function remember(array $keep): void
    {
        $found = $this->connection()->query(
            "SELECT name FROM pragma_table_list WHERE schema = 'main' AND type = 'table'
             AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' AND name NOT LIKE 'specrig\\_%' ESCAPE '\\' ORDER BY name",
        );
        $this->tables = array_values(array_diff($found->fetchAll(\PDO::FETCH_COLUMN), $keep));
        if (is_file($this->kept())) {
            unlink($this->kept());
        }
        $this->attached(function (\PDO $connection) {
            foreach ($this->tables as $table) {
                $this->rememberRows($connection, $table);
            }
            if ($connection->query("SELECT EXISTS (SELECT 1 FROM main.sqlite_schema WHERE name = 'sqlite_sequence')")->fetchColumn()) {
                $sequences = $connection->query('SELECT name, seq FROM main.sqlite_sequence')->fetchAll(\PDO::FETCH_NUM);
                $reset = array_filter($sequences, fn (array $row) => in_array($row[0], $this->tables, true));
                $this->sequences = array_values(array_map(fn (array $row) => [(string) $row[0], (int) $row[1]], $reset));
            }
        });
    }

    protected function restore(): void
    {
        $this->attached(function (\PDO $connection) {
            $connection->exec('PRAGMA foreign_keys = OFF');
            $connection->beginTransaction();
            try {
                $triggers = $connection->query("SELECT name, sql FROM main.sqlite_schema WHERE type = 'trigger'")->fetchAll(\PDO::FETCH_NUM);
                foreach ($triggers as [$trigger]) {
                    $connection->exec('DROP TRIGGER main.' . self::quoted($trigger));
                }
                foreach ($this->tables as $table) {
                    $connection->exec('DELETE FROM main.' . self::quoted($table));
                }
                foreach ($this->inserts as $insert) {
                    $connection->exec($insert);
                }
                if ($this->sequences !== null) {
                    $connection->exec('DELETE FROM main.sqlite_sequence WHERE name IN (' . implode(', ', array_map($connection->quote(...), $this->tables)) . ')');
                    $put = $connection->prepare('INSERT INTO main.sqlite_sequence (name, seq) VALUES (?, ?)');
                    foreach ($this->sequences as $sequence) {
                        $put->execute($sequence);
                    }
                }
                foreach ($triggers as [, $sql]) {
                    $connection->exec($sql);
                }
                $connection->commit();
            } catch (\Throwable $e) {
                $connection->rollBack();
                throw $e;
            }
        });
    }

    private function rememberRows(\PDO $connection, string $table): void
    {
        if (!$connection->query('SELECT EXISTS (SELECT 1 FROM main.' . self::quoted($table) . ')')->fetchColumn()) {
            return;
        }
        $found = $connection->prepare("SELECT name FROM pragma_table_xinfo(?, 'main') WHERE hidden = 0 ORDER BY cid");
        $found->execute([$table]);
        $columns = implode(', ', array_map(self::quoted(...), $found->fetchAll(\PDO::FETCH_COLUMN)));
        $kept = self::BASELINE . '.' . self::quoted($table);
        $connection->exec("CREATE TABLE {$kept} AS SELECT {$columns} FROM main." . self::quoted($table));
        $this->inserts[] = 'INSERT INTO main.' . self::quoted($table) . " ({$columns}) SELECT {$columns} FROM {$kept}";
    }

    /** @param \Closure(\PDO): void $do */
    private function attached(\Closure $do): void
    {
        $connection = Address::parse($this->address)->pdo();
        $connection->prepare('ATTACH DATABASE ? AS ' . self::BASELINE)->execute([$this->kept()]);
        $do($connection);
    }

    private function kept(): string
    {
        return "{$this->directory}/baseline.db";
    }

    private function down(): void
    {
        $this->close();
        foreach (glob("{$this->directory}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private static function quoted(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
