<?php

declare(strict_types=1);

namespace Specrig\Sql;

use Specrig\Docker;
use Specrig\Rig;

abstract class Server extends Engine
{
    private const MARK = 'specrig ';

    protected const KIND = '';

    protected const PORT = 0;

    protected const MAINTENANCE = '';

    /** @var list<string> */
    protected const TRUST = [];

    final protected function __construct(private readonly string $maintenance, protected readonly string $database, string $address)
    {
        parent::__construct($address);
    }

    public static function start(string $service, ?string $given, string $image): static
    {
        $admin = Address::parse($given ?? static::container($service, $image));
        $stem = in_array($admin->database, ['', static::MAINTENANCE], true) ? "{$service}_test" : $admin->database;
        $database = "{$stem}_" . getmypid();
        $maintenance = $admin->with(database: static::MAINTENANCE);
        try {
            $connection = self::connect($maintenance, $given === null ? 60 : 0);
            self::sweep($connection, $stem);
            static::drop($connection, $database);
            static::create($connection, $database, self::MARK . time());
            $query = static::query($connection);
        } catch (\PDOException $e) {
            throw new \RuntimeException(static::KIND . " at {$admin->host}:{$admin->port}: {$e->getMessage()}", 0, $e);
        }
        $address = $admin->with(database: $database, query: $query + $admin->query);
        $server = new static((string) $maintenance, $database, (string) $address);
        Rig::ending(fn () => $server->down());
        return $server;
    }

    // Nothing listens on port 1, so whatever connects there is refused.
    public function refusing(): string
    {
        return (string) Address::parse($this->address)->with(host: '127.0.0.1', port: 1);
    }

    protected function name(): string
    {
        return $this->database;
    }

    abstract protected static function create(\PDO $admin, string $database, string $mark): void;

    abstract protected static function drop(\PDO $admin, string $database): void;

    /** @return list<array{string, string, int}> every database, its mark, and how many are connected to it */
    abstract protected static function marks(\PDO $admin): array;

    /** @return array<string, string> what the address carries for the service's driver */
    abstract protected static function query(\PDO $admin): array;

    private static function container(string $service, string $image): string
    {
        $docker = new Docker();
        $labels = ['specrig' => static::KIND, 'specrig.image' => $image];
        try {
            $found = $docker->containers($labels)[0] ?? null;
            if ($found === null) {
                try {
                    $id = $docker->run($image, static::PORT, static::TRUST, $labels, 'specrig-' . static::KIND . '-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($image)) . '-' . Docker::published());
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
            $port = $docker->port($id, static::PORT);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                static::KIND . ': no address is given and no container started — set ' . strtoupper($service) . '_TEST_' . strtoupper(static::KIND) . ", or start Docker: {$e->getMessage()}",
                0,
                $e,
            );
        }
        return (string) Address::parse(static::KIND . '://' . Docker::host() . ":{$port}/")->with(database: static::MAINTENANCE);
    }

    // The images start a server of their own on a socket while they initialise, so TCP
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

    private static function sweep(\PDO $connection, string $stem): void
    {
        $ours = '/^' . preg_quote($stem, '/') . '_[0-9]+$/';
        $hourAgo = time() - 3600;
        foreach (static::marks($connection) as [$name, $mark, $connected]) {
            if (preg_match($ours, $name) && $connected === 0 && str_starts_with($mark, self::MARK)
                && (int) substr($mark, strlen(self::MARK)) < $hourAgo) {
                static::drop($connection, $name);
            }
        }
    }

    private function down(): void
    {
        $this->close();
        static::drop(Address::parse($this->maintenance)->pdo(), $this->database);
    }
}
