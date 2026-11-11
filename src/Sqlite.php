<?php

declare(strict_types=1);

namespace Specrig;

use Specrig\Sql\Engine;
use Specrig\Sqlite\File;

// No refuseCommit: only a deferred foreign key fails a commit here, reported as a broken key.
final class Sqlite extends Database
{
    /** @var array<string, File> */
    private static array $files = [];

    protected function start(string $service): Engine
    {
        return self::$files[$service] ??= File::start($service);
    }
}
