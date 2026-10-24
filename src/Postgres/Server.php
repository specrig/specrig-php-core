<?php

declare(strict_types=1);

namespace Specrig\Postgres;

use Specrig\Docker;
use Specrig\Rig;

final class Server
{
    private const MARK = 'specrig ';

    private ?\PDO $connection = null;

    private \Throwable|false|null $migration = null;

    /** @var list<string> */
    private array $tables = [];

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
                $this->tables = $this->discover($keep);
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
        if ($this->tables !== []) {
            $this->connection()->exec('TRUNCATE TABLE ' . implode(', ', $this->tables) . ' RESTART IDENTITY CASCADE');
        }
    }

    // Nothing listens on port 1, so whatever connects there is refused.
    public function refusing(): string
    {
        return (string) Address::parse($this->address)->with(host: '127.0.0.1', port: 1);
    }

    /**
     * @param list<string> $keep
     * @return list<string>
     */
    private function discover(array $keep): array
    {
        $tables = [];
        $found = $this->connection()->query("SELECT schemaname, tablename FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema') ORDER BY 1, 2");
        foreach ($found->fetchAll(\PDO::FETCH_NUM) as [$schema, $table]) {
            if (!in_array($table, $keep, true)) {
                $tables[] = self::quoted($schema) . '.' . self::quoted($table);
            }
        }
        return $tables;
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
