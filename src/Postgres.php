<?php

declare(strict_types=1);

namespace Specrig;

use Specrig\Postgres\Server;
use Specrig\Sql\Engine;

final class Postgres extends Database
{
    /** @var array<string, Server> */
    private static array $servers = [];

    /** @param list<string> $keep */
    public function __construct(
        string $setting,
        private readonly ?string $address = null,
        private readonly string $image = 'postgres:17-alpine',
        array $keep = [],
    ) {
        parent::__construct($setting, $keep);
    }

    public function refuseCommit(string $table, Write ...$writes): void
    {
        $this->refused(fn (Server $server) => $server->refuse($table, 0, $writes, deferred: true));
    }

    protected function start(string $service): Engine
    {
        $given = $this->address ?? Environment::get(strtoupper($service) . '_TEST_POSTGRES');
        return self::$servers[($given ?? $this->image) . "\n{$service}"] ??= Server::start($service, $given, $this->image);
    }
}
