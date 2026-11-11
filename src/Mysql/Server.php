<?php

declare(strict_types=1);

namespace Specrig\Mysql;

use Specrig\Sql\Address;

final class Server extends \Specrig\Sql\Server
{
    protected const KIND = 'mysql';

    protected const PORT = 3306;

    protected const TRUST = ['MYSQL_ALLOW_EMPTY_PASSWORD=yes'];

    private const QUOTA = 'specrig_refusal';

    /** @var array<string, string> */
    private array $inserts = [];

    /** @var array<string, string> */
    private array $checksums = [];

    /** @var array<string, int> */
    private array $increments = [];

    /** @var list<array{name: string, table: string, event: string, mode: string, statement: string}> */
    private array $triggers = [];

    public function refuse(string $table, int $rows, array $writes): void
    {
        $events = self::events($writes);
        $name = 'specrig_refuse_' . implode('_', $events);
        $connection = $this->connection();
        try {
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::QUOTA . ' (relation varchar(64) NOT NULL, name varchar(64) NOT NULL, remaining int NOT NULL, PRIMARY KEY (relation, name))');
            $connection->prepare('INSERT INTO ' . self::QUOTA . ' (relation, name, remaining) VALUES (?, ?, ?) AS kept ON DUPLICATE KEY UPDATE remaining = kept.remaining')
                ->execute([$table, $name, $rows]);
            $quota = 'relation = ' . $connection->quote($table) . ' AND name = ' . $connection->quote($name);
            foreach ($events as $event) {
                $trigger = self::quoted('specrig_refuse_' . hash('fnv1a64', "{$name} {$event} {$table}"));
                $connection->exec("DROP TRIGGER IF EXISTS {$trigger}");
                $connection->exec("CREATE TRIGGER {$trigger} BEFORE {$event} ON " . self::quoted($table) . ' FOR EACH ROW BEGIN '
                    . 'UPDATE ' . self::QUOTA . " SET remaining = remaining - 1 WHERE {$quota}; "
                    . 'IF (SELECT remaining FROM ' . self::QUOTA . " WHERE {$quota}) < 0 THEN "
                    . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = " . $connection->quote("specrig: {$event} on {$table} is refused") . '; '
                    . 'END IF; END');
            }
        } catch (\PDOException $e) {
            throw new \RuntimeException("cannot refuse writes to {$table}: {$e->getMessage()}", 0, $e);
        }
    }

    public function lift(): void
    {
        $connection = $this->connection();
        $triggers = $connection->query("SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = database() AND trigger_name LIKE 'specrig\\_refuse\\_%'");
        foreach ($triggers->fetchAll(\PDO::FETCH_COLUMN) as $trigger) {
            $connection->exec('DROP TRIGGER ' . self::quoted($trigger));
        }
        // Asked first: a drop is DDL, and costs as much, even when there is nothing to drop.
        $quotas = $connection->query("SELECT count(*) FROM information_schema.tables WHERE table_schema = database() AND table_name = '" . self::QUOTA . "'");
        if ((int) $quotas->fetchColumn() > 0) {
            $connection->exec('DROP TABLE ' . self::QUOTA);
        }
    }

    protected function remember(array $keep): void
    {
        $found = $this->connection()->query("SELECT table_name FROM information_schema.tables WHERE table_schema = database() AND table_type = 'BASE TABLE' AND table_name NOT LIKE 'specrig\\_%' ORDER BY table_name");
        $this->tables = array_values(array_diff($found->fetchAll(\PDO::FETCH_COLUMN), $keep));
        if ($this->tables === []) {
            return;
        }
        $this->throwaway(function (\PDO $connection) {
            $filled = [];
            foreach ($this->tables as $i => $table) {
                if ($this->rememberRows($connection, $i, $table)) {
                    $filled[] = $table;
                }
            }
            $this->checksums = $this->checksums($connection);
            $this->increments = $this->increments($connection);
            $this->rememberTriggers($connection, $filled);
        });
    }

    // DELETE, and only where a case wrote: TRUNCATE, a trigger and a counter are DDL, tens of ms each.
    protected function restore(): void
    {
        $this->throwaway(function (\PDO $connection) {
            $checksums = $this->checksums($connection);
            $changed = array_values(array_filter($this->tables, fn (string $table) => $checksums[$table] !== $this->checksums[$table]));
            $present = $connection->query('SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = database()')->fetchAll(\PDO::FETCH_COLUMN);
            // A delete trigger fires only on rows there are to delete.
            $firing = array_values(array_filter($this->triggers, fn (array $trigger) => in_array($trigger['name'], $present, true)
                && in_array($trigger['table'], $changed, true) && ($trigger['event'] === 'INSERT' || $checksums[$trigger['table']] !== '0')));
            $made = array_values(array_filter($this->triggers, fn (array $trigger) => !in_array($trigger['name'], $present, true) || in_array($trigger, $firing, true)));
            foreach ($firing as $trigger) {
                $connection->exec('DROP TRIGGER ' . self::quoted($trigger['name']));
            }
            $this->refill($connection, $changed);
            $this->rewind($connection);
            foreach ($made as $trigger) {
                $connection->exec('SET SESSION sql_mode = ' . $connection->quote($trigger['mode']));
                $connection->exec($trigger['statement']);
            }
        });
    }

    protected static function create(\PDO $admin, string $database, string $mark): void
    {
        $admin->exec('CREATE DATABASE ' . self::quoted($database));
        // MySQL keeps no comment on a database, so the mark is a table of its own in it.
        $admin->exec('CREATE TABLE ' . self::quoted($database) . '.specrig_mark (id int) COMMENT ' . $admin->quote($mark));
    }

    protected static function drop(\PDO $admin, string $database): void
    {
        $connected = $admin->prepare('SELECT id FROM information_schema.processlist WHERE db = ?');
        $connected->execute([$database]);
        foreach ($connected->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            try {
                $admin->exec('KILL ' . (int) $id);
            } catch (\PDOException) {
                // gone by itself in between
            }
        }
        $admin->exec('DROP DATABASE IF EXISTS ' . self::quoted($database));
    }

    protected static function marks(\PDO $admin): array
    {
        $databases = $admin->query(
            "SELECT s.schema_name, coalesce(t.table_comment, ''),
                    (SELECT count(*) FROM information_schema.processlist p WHERE p.db = s.schema_name)
             FROM information_schema.schemata s
             LEFT JOIN information_schema.tables t ON t.table_schema = s.schema_name AND t.table_name = 'specrig_mark'",
        );
        return array_map(fn (array $row) => [(string) $row[0], (string) $row[1], (int) $row[2]], $databases->fetchAll(\PDO::FETCH_NUM));
    }

    protected static function query(\PDO $admin): array
    {
        return ['serverVersion' => (string) $admin->query('SELECT version()')->fetchColumn(), 'charset' => 'utf8mb4'];
    }

    private static function quoted(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    // The settings stay with a connection, so it is one of its own, closed after.
    /** @param \Closure(\PDO): void $do */
    private function throwaway(\Closure $do): void
    {
        $connection = Address::parse($this->address)->pdo();
        $connection->exec("SET SESSION foreign_key_checks = 0, SESSION information_schema_stats_expiry = 0, SESSION sql_mode = concat_ws(',', nullif(@@session.sql_mode, ''), 'NO_AUTO_VALUE_ON_ZERO')");
        $do($connection);
    }

    private function rememberRows(\PDO $connection, int $i, string $table): bool
    {
        if (!$connection->query('SELECT EXISTS (SELECT 1 FROM ' . self::quoted($table) . ')')->fetchColumn()) {
            return false;
        }
        $found = $connection->prepare(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = database() AND table_name = ? AND generation_expression = '' ORDER BY ordinal_position",
        );
        $found->execute([$table]);
        $columns = implode(', ', array_map(self::quoted(...), $found->fetchAll(\PDO::FETCH_COLUMN)));
        $kept = self::quoted("specrig_kept_{$i}");
        $connection->exec("DROP TABLE IF EXISTS {$kept}");
        $connection->exec("CREATE TABLE {$kept} AS SELECT {$columns} FROM " . self::quoted($table));
        $this->inserts[$table] = 'INSERT INTO ' . self::quoted($table) . " ({$columns}) SELECT {$columns} FROM {$kept}";
        return true;
    }

    /** @return array<string, string> */
    private function checksums(\PDO $connection): array
    {
        $found = $connection->query('CHECKSUM TABLE ' . implode(', ', array_map(self::quoted(...), $this->tables)))->fetchAll(\PDO::FETCH_NUM);
        $checksums = [];
        foreach ($this->tables as $i => $table) {
            $checksums[$table] = (string) ($found[$i][1] ?? '');
        }
        return $checksums;
    }

    /** @return array<string, int> */
    private function increments(\PDO $connection): array
    {
        $found = $connection->query('SELECT table_name, auto_increment FROM information_schema.tables WHERE table_schema = database() AND auto_increment IS NOT NULL');
        $increments = [];
        foreach ($found->fetchAll(\PDO::FETCH_NUM) as [$table, $next]) {
            if (in_array($table, $this->tables, true)) {
                $increments[(string) $table] = (int) $next;
            }
        }
        return $increments;
    }

    /** @param list<string> $filled */
    private function rememberTriggers(\PDO $connection, array $filled): void
    {
        $found = $connection->query(
            "SELECT trigger_name, event_object_table, event_manipulation FROM information_schema.triggers
             WHERE trigger_schema = database() AND event_manipulation IN ('INSERT', 'DELETE')
             ORDER BY event_object_table, event_manipulation, action_order",
        );
        $this->triggers = [];
        foreach ($found->fetchAll(\PDO::FETCH_NUM) as [$name, $table, $event]) {
            if ($event === 'DELETE' && in_array($table, $this->tables, true) || in_array($table, $filled, true)) {
                [, $mode, $statement] = $connection->query('SHOW CREATE TRIGGER ' . self::quoted($name))->fetch(\PDO::FETCH_NUM);
                $this->triggers[] = ['name' => $name, 'table' => $table, 'event' => $event, 'mode' => $mode, 'statement' => $statement];
            }
        }
    }

    /** @param list<string> $tables */
    private function refill(\PDO $connection, array $tables): void
    {
        if ($tables === []) {
            return;
        }
        $connection->beginTransaction();
        try {
            foreach ($tables as $table) {
                $connection->exec('DELETE FROM ' . self::quoted($table));
            }
            foreach ($tables as $table) {
                if (isset($this->inserts[$table])) {
                    $connection->exec($this->inserts[$table]);
                }
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    private function rewind(\PDO $connection): void
    {
        $current = $this->increments($connection);
        foreach ($this->increments as $table => $next) {
            if (($current[$table] ?? null) !== $next) {
                $connection->exec('ALTER TABLE ' . self::quoted($table) . " AUTO_INCREMENT = {$next}");
            }
        }
    }
}
