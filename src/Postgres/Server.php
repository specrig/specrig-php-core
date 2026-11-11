<?php

declare(strict_types=1);

namespace Specrig\Postgres;

use Specrig\Docker;
use Specrig\Rig;
use Specrig\Write;

final class Server
{
    private const MARK = 'specrig ';

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

    private ?\PDO $connection = null;

    private \Throwable|false|null $migration = null;

    /** @var list<string> */
    private array $tables = [];

    /** @var list<string> */
    private array $restores = [];

    /** @var list<string> */
    private array $sequences = [];

    private function __construct(private readonly string $maintenance, private readonly string $database, public readonly string $address)
    {
    }

    public static function start(string $service, ?string $given, string $image): self
    {
        $admin = Address::parse($given ?? self::container($service, $image));
        $stem = in_array($admin->database, ['', 'postgres'], true) ? "{$service}_test" : $admin->database;
        $database = "{$stem}_" . getmypid();
        $maintenance = $admin->with(database: 'postgres');
        try {
            $connection = self::connect($maintenance, $given === null ? 60 : 0);
            self::sweep($connection, $stem);
            $connection->exec('DROP DATABASE IF EXISTS ' . self::quoted($database) . ' WITH (FORCE)');
            $connection->exec('CREATE DATABASE ' . self::quoted($database));
            $connection->exec('COMMENT ON DATABASE ' . self::quoted($database) . " IS '" . self::MARK . time() . "'");
            $version = (string) $connection->query("SELECT current_setting('server_version')")->fetchColumn();
        } catch (\PDOException $e) {
            throw new \RuntimeException("postgres at {$admin->host}:{$admin->port}: {$e->getMessage()}", 0, $e);
        }
        // Doctrine reads the server's version from the address, as Symfony's recipe writes it.
        $address = $admin->with(database: $database, query: ['serverVersion' => strtok($version, ' '), 'charset' => 'utf8'] + $admin->query);
        $server = new self((string) $maintenance, $database, (string) $address);
        Rig::ending(fn () => $server->down());
        return $server;
    }

    private static function container(string $service, string $image): string
    {
        $docker = new Docker();
        $labels = ['specrig' => 'postgres', 'specrig.image' => $image];
        try {
            $found = $docker->containers($labels)[0] ?? null;
            if ($found === null) {
                try {
                    $id = $docker->run($image, 5432, ['POSTGRES_HOST_AUTH_METHOD=trust'], $labels, 'specrig-postgres-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($image)));
                } catch (\RuntimeException $e) {
                    if ($e->getCode() !== 409) {
                        throw $e;
                    }
                    $id = $docker->containers($labels)[0]['Id'];
                }
            } else {
                $id = $found['Id'];
                if ($found['State'] !== 'running') {
                    $docker->start($id);
                }
            }
            $port = $docker->port($id, 5432);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                'postgres: no address is given and no container started — set ' . strtoupper($service) . "_TEST_POSTGRES, or start Docker: {$e->getMessage()}",
                0,
                $e,
            );
        }
        return 'postgresql://postgres@' . Docker::host() . ":{$port}/postgres";
    }

    // The image starts a server of its own on a socket while it initialises, so TCP
    // answers only once the real one is up.
    private static function connect(Address $address, int $patience): \PDO
    {
        $deadline = time() + $patience;
        while (true) {
            try {
                return $address->pdo();
            } catch (\PDOException $e) {
                if (time() >= $deadline) {
                    throw $e;
                }
                usleep(250_000);
            }
        }
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
                foreach ($this->discover($keep) as [$schema, $table]) {
                    $this->remember($schema, $table);
                }
                $this->reset();
            } catch (\Throwable $e) {
                $this->migration = $e;
            }
        }
        if ($this->migration instanceof \Throwable) {
            throw new \RuntimeException("{$this->database} did not migrate: {$this->migration->getMessage()}", 0, $this->migration);
        }
    }

    public function reset(): void
    {
        $this->lift();
        if ($this->tables === []) {
            return;
        }
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

    /** @param list<Write> $writes */
    public function refuse(string $table, int $rows, array $writes, bool $deferred = false): void
    {
        $events = array_map(fn (Write $write) => $write->value, array_filter(Write::cases(), fn (Write $write) => $writes === [] || in_array($write, $writes, true)));
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

    // Nothing listens on port 1, so whatever connects there is refused.
    public function refusing(): string
    {
        return (string) Address::parse($this->address)->with(host: '127.0.0.1', port: 1);
    }

    /**
     * @param list<string> $keep
     * @return list<array{string, string}>
     */
    private function discover(array $keep): array
    {
        $tables = [];
        $found = $this->connection()->query("SELECT schemaname, tablename FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema') ORDER BY 1, 2");
        foreach ($found->fetchAll(\PDO::FETCH_NUM) as [$schema, $table]) {
            if (!in_array($table, $keep, true)) {
                $tables[] = [$schema, $table];
            }
        }
        return $tables;
    }

    private function remember(string $schema, string $table): void
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

    private function connection(): \PDO
    {
        return $this->connection ??= Address::parse($this->address)->pdo();
    }

    private function down(): void
    {
        $this->connection = null;
        Address::parse($this->maintenance)->pdo()->exec('DROP DATABASE IF EXISTS ' . self::quoted($this->database) . ' WITH (FORCE)');
    }

    private static function sweep(\PDO $connection, string $stem): void
    {
        $ours = '/^' . preg_quote($stem, '/') . '_[0-9]+$/';
        $hourAgo = time() - 3600;
        $databases = $connection->query(
            "SELECT d.datname, coalesce(shobj_description(d.oid, 'pg_database'), ''),
                    (SELECT count(*) FROM pg_stat_activity a WHERE a.datname = d.datname)
             FROM pg_database d",
        );
        foreach ($databases->fetchAll(\PDO::FETCH_NUM) as [$name, $mark, $connected]) {
            if (preg_match($ours, $name) && (int) $connected === 0 && str_starts_with($mark, self::MARK)
                && (int) substr($mark, strlen(self::MARK)) < $hourAgo) {
                $connection->exec('DROP DATABASE IF EXISTS ' . self::quoted($name) . ' WITH (FORCE)');
            }
        }
    }

    private static function quoted(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
