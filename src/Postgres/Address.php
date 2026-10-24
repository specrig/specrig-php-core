<?php

declare(strict_types=1);

namespace Specrig\Postgres;

final class Address
{
    /** @param array<string, string> $query */
    private function __construct(
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
        $parts = parse_url($url);
        if (!isset($parts['host'])) {
            throw new \InvalidArgumentException('a postgres address is a URL: postgresql://user:password@host:port/database');
        }
        parse_str($parts['query'] ?? '', $query);
        return new self(
            $parts['host'],
            $parts['port'] ?? 5432,
            rawurldecode($parts['user'] ?? 'postgres'),
            rawurldecode($parts['pass'] ?? ''),
            ltrim(rawurldecode($parts['path'] ?? ''), '/'),
            $query,
        );
    }

    /** @param array<string, string>|null $query */
    public function with(?string $host = null, ?int $port = null, ?string $database = null, ?array $query = null): self
    {
        return new self($host ?? $this->host, $port ?? $this->port, $this->user, $this->password, $database ?? $this->database, $query ?? $this->query);
    }

    public function pdo(): \PDO
    {
        return new \PDO(
            "pgsql:host={$this->host};port={$this->port};dbname={$this->database}",
            $this->user,
            $this->password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5],
        );
    }

    public function __toString(): string
    {
        $credentials = rawurlencode($this->user) . ($this->password === '' ? '' : ':' . rawurlencode($this->password));
        $query = $this->query === [] ? '' : '?' . http_build_query($this->query);
        return "postgresql://{$credentials}@{$this->host}:{$this->port}/" . rawurlencode($this->database) . $query;
    }
}
