<?php

declare(strict_types=1);

namespace Specrig\Postgres;

use Specrig\Write;

final class Server extends \Specrig\Sql\Server
{
    protected const KIND = 'postgres';

    protected const PORT = 5432;

    protected const MAINTENANCE = 'postgres';

    protected const TRUST = ['POSTGRES_HOST_AUTH_METHOD=trust'];

    private const BASELINE = 'specrig_baseline';

    // A schema of its own, so that neither discovery nor the baseline copy lists it.
    private const REFUSAL = <<<'SQL'
        create schema if not exists specrig_refusal;
        create table if not exists specrig_refusal.quota (
            relation oid,
            name text,
            remaining int not null,
            primary key (relation, name)
        );
        create or replace function specrig_refusal.refuse() returns trigger language plpgsql as $$
        declare
            rest int;
        begin
            update specrig_refusal.quota set remaining = remaining - 1
            where relation = tg_relid and name = tg_name
            returning remaining into rest;
            if rest < 0 then
                raise exception 'specrig: % on % is refused', lower(tg_op), tg_table_name;
            end if;
            if tg_op = 'DELETE' then
                return old;
            end if;
            return new;
        end $$;
        SQL;

    /** @var list<string> */
    private array $restores = [];

    /** @var list<string> */
    private array $sequences = [];

    /** @param list<Write> $writes */
    public function refuse(string $table, int $rows, array $writes, bool $deferred = false): void
    {
        $events = self::events($writes);
        $name = 'specrig_refuse_' . ($deferred ? 'commit_' : '') . implode('_', $events);
        $on = implode('.', array_map(self::quoted(...), explode('.', $table, 2)));
        $trigger = $deferred
            ? "create constraint trigger {$name} after " . implode(' or ', $events) . " on {$on} deferrable initially deferred for each row"
            : "create trigger {$name} before " . implode(' or ', $events) . " on {$on} for each row";
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $connection->exec(self::REFUSAL);
            $connection->prepare(
                'INSERT INTO specrig_refusal.quota (relation, name, remaining) VALUES (?::regclass, ?, ?)
                 ON CONFLICT (relation, name) DO UPDATE SET remaining = excluded.remaining',
            )->execute([$on, $name, $rows]);
            $connection->exec("DROP TRIGGER IF EXISTS {$name} ON {$on}");
            $connection->exec("{$trigger} execute function specrig_refusal.refuse()");
            $connection->commit();
        } catch (\PDOException $e) {
            $connection->rollBack();
            throw new \RuntimeException("cannot refuse writes to {$table}: {$e->getMessage()}", 0, $e);
        }
    }

    public function lift(): void
    {
        $this->connection()->exec('DROP SCHEMA IF EXISTS specrig_refusal CASCADE');
    }

    protected function remember(array $keep): void
    {
        $found = $this->connection()->query("SELECT schemaname, tablename FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema') ORDER BY 1, 2");
        foreach ($found->fetchAll(\PDO::FETCH_NUM) as [$schema, $table]) {
            if (!in_array($table, $keep, true)) {
                $this->rememberTable($schema, $table);
            }
        }
    }

    protected function restore(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            if ($this->restores !== []) {
                try {
                    $connection->exec('SET LOCAL session_replication_role = replica');
                } catch (\PDOException $e) {
                    throw new \RuntimeException("putting back the rows the migrations left needs a superuser: {$e->getMessage()}", 0, $e);
                }
            }
            $connection->exec('TRUNCATE TABLE ' . implode(', ', $this->tables) . ' RESTART IDENTITY CASCADE');
            foreach ($this->restores as $restore) {
                $connection->exec($restore);
            }
            if ($this->sequences !== []) {
                $connection->exec('SELECT setval(name::regclass, last_value, is_called) FROM (VALUES ' . implode(', ', $this->sequences) . ') AS kept (name, last_value, is_called)');
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    protected static function create(\PDO $admin, string $database, string $mark): void
    {
        $admin->exec('CREATE DATABASE ' . self::quoted($database));
        $admin->exec('COMMENT ON DATABASE ' . self::quoted($database) . ' IS ' . $admin->quote($mark));
    }

    protected static function drop(\PDO $admin, string $database): void
    {
        $admin->exec('DROP DATABASE IF EXISTS ' . self::quoted($database) . ' WITH (FORCE)');
    }

    protected static function marks(\PDO $admin): array
    {
        $databases = $admin->query(
            "SELECT d.datname, coalesce(shobj_description(d.oid, 'pg_database'), ''),
                    (SELECT count(*) FROM pg_stat_activity a WHERE a.datname = d.datname)
             FROM pg_database d",
        );
        return array_map(fn (array $row) => [(string) $row[0], (string) $row[1], (int) $row[2]], $databases->fetchAll(\PDO::FETCH_NUM));
    }

    // Doctrine reads the server's version from the address, as Symfony's recipe writes it.
    protected static function query(\PDO $admin): array
    {
        $version = (string) $admin->query("SELECT current_setting('server_version')")->fetchColumn();
        return ['serverVersion' => strtok($version, ' '), 'charset' => 'utf8'];
    }

    private function rememberTable(string $schema, string $table): void
    {
        $connection = $this->connection();
        $name = self::quoted($schema) . '.' . self::quoted($table);
        $this->tables[] = $name;
        $found = $connection->prepare(
            'SELECT column_name, is_generated, pg_get_serial_sequence(?, column_name) FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
        );
        $found->execute([$name, $schema, $table]);
        $insertable = [];
        foreach ($found->fetchAll(\PDO::FETCH_NUM) as [$column, $generated, $sequence]) {
            if ($generated === 'NEVER') {
                $insertable[] = self::quoted($column);
            }
            if ($sequence !== null) {
                [$last, $called] = $connection->query("SELECT last_value, is_called FROM {$sequence}")->fetch(\PDO::FETCH_NUM);
                $this->sequences[] = '(' . $connection->quote($sequence) . ", {$last}, " . ($called ? 'true' : 'false') . ')';
            }
        }
        $filled = $connection->prepare("SELECT NOT relispartition AND EXISTS (SELECT 1 FROM {$name}) FROM pg_class WHERE oid = ?::regclass");
        $filled->execute([$name]);
        if ($filled->fetchColumn()) {
            $columns = implode(', ', $insertable);
            $copy = self::BASELINE . '.t' . count($this->restores);
            $connection->exec('CREATE SCHEMA IF NOT EXISTS ' . self::BASELINE);
            $connection->exec("CREATE TABLE {$copy} AS SELECT {$columns} FROM {$name}");
            $this->restores[] = "INSERT INTO {$name} ({$columns}) OVERRIDING SYSTEM VALUE SELECT {$columns} FROM {$copy}";
        }
    }

    private static function quoted(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
