<?php

declare(strict_types=1);

namespace Specrig\Sql;

final class Address
{
    private const SCHEMES = ['postgres' => 'postgresql', 'postgresql' => 'postgresql', 'pgsql' => 'postgresql', 'mysql' => 'mysql', 'mysql2' => 'mysql'];

    private const DEFAULTS = ['postgresql' => [5432, 'postgres'], 'mysql' => [3306, 'root']];

    /** @param array<string, string> $query */
    private function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly string $user,
        public readonly string $password,
        public readonly string $database,
        public readonly array $query,
    ) {
    }

    public static function parse(string $url): self
    {
        if (str_starts_with($url, 'sqlite:///')) {
            [$path, $query] = array_pad(explode('?', substr($url, strlen('sqlite:///')), 2), 2, '');
            parse_str($query, $parsed);
            return self::file(rawurldecode($path), $parsed);
        }
        $parts = parse_url($url);
        $scheme = self::SCHEMES[$parts['scheme'] ?? ''] ?? null;
        if (!isset($parts['host']) || $scheme === null) {
            throw new \InvalidArgumentException('a database address is a URL: postgresql://user:password@host:port/database, mysql://… or sqlite:////path/to/file');
        }
        parse_str($parts['query'] ?? '', $query);
        [$port, $user] = self::DEFAULTS[$scheme];
        return new self(
            $scheme,
            $parts['host'],
            $parts['port'] ?? $port,
            rawurldecode($parts['user'] ?? $user),
            rawurldecode($parts['pass'] ?? ''),
            ltrim(rawurldecode($parts['path'] ?? ''), '/'),
            $query,
        );
    }

    /** @param array<string, string> $query */
    public static function file(string $path, array $query = []): self
    {
        return new self('sqlite', '', 0, '', '', $path, $query);
    }

    /** @param array<string, string>|null $query */
    public function with(?string $host = null, ?int $port = null, ?string $database = null, ?array $query = null): self
    {
        return new self($this->scheme, $host ?? $this->host, $port ?? $this->port, $this->user, $this->password, $database ?? $this->database, $query ?? $this->query);
    }

    // SQLite's busy timeout is PDO's timeout, and its foreign keys are off until a connection turns them on.
    public function pdo(): \PDO
    {
        $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5];
        if ($this->scheme === 'sqlite') {
            $pdo = new \PDO("sqlite:{$this->database}", null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON');
            return $pdo;
        }
        $driver = $this->scheme === 'mysql' ? 'mysql' : 'pgsql';
        $database = $this->database === '' && $driver === 'mysql' ? '' : ";dbname={$this->database}";
        return new \PDO("{$driver}:host={$this->host};port={$this->port}{$database}", $this->user, $this->password, $options);
    }

    public function __toString(): string
    {
        $query = $this->query === [] ? '' : '?' . http_build_query($this->query);
        if ($this->scheme === 'sqlite') {
            return 'sqlite:///' . implode('/', array_map(rawurlencode(...), explode('/', $this->database))) . $query;
        }
        $credentials = rawurlencode($this->user) . ($this->password === '' ? '' : ':' . rawurlencode($this->password));
        return "{$this->scheme}://{$credentials}@{$this->host}:{$this->port}/" . rawurlencode($this->database) . $query;
    }
}
