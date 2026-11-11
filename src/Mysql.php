<?php

declare(strict_types=1);

namespace Specrig;

use Specrig\Mysql\Server;
use Specrig\Sql\Engine;

// No refuseCommit: MySQL checks no constraint at the commit, so nothing could fail there.
final class Mysql extends Database
{
    /** @var array<string, Server> */
    private static array $servers = [];

    /** @param list<string> $keep */
    public function __construct(
        string $setting,
        private readonly ?string $address = null,
        private readonly string $image = 'mysql:8.4',
        array $keep = [],
    ) {
        parent::__construct($setting, $keep);
    }

    protected function start(string $service): Engine
    {
        $given = $this->address ?? Environment::get(strtoupper($service) . '_TEST_MYSQL');
        return self::$servers[($given ?? $this->image) . "\n{$service}"] ??= Server::start($service, $given, $this->image);
    }
}
