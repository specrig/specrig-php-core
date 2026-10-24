<?php

declare(strict_types=1);

namespace Specrig;

use Specrig\Postgres\Server;

final class Postgres implements Resource
{
    /** @var array<string, Server> */
    private static array $servers = [];

    private ?Server $server = null;

    private ?Rig $rig = null;

    private bool $refusing = false;

    private ?\Throwable $error = null;

    /** @param list<string> $keep */
    public function __construct(
        private readonly string $setting,
        private readonly ?string $address = null,
        private readonly string $image = 'postgres:17-alpine',
        private readonly array $keep = [],
    ) {
    }

    public function setting(): string
    {
        return $this->setting;
    }

    public function attach(Trace $trace, \Closure $boot): void
    {
    }

    public function provision(string $service): string
    {
        $name = preg_replace('/[^a-z0-9]/', '_', strtolower($service));
        $given = $this->address ?? Environment::get(strtoupper($name) . '_TEST_POSTGRES');
        $this->server = self::$servers[($given ?? $this->image) . "\n{$name}"] ??= Server::start($name, $given, $this->image);
        return $this->server->address;
    }

    public function ready(Rig $rig): void
    {
        $databases = $rig->databases();
        $this->server()->once(fn () => $databases->migrate(), [...$this->keep, ...$databases->bookkeeping()]);
        $this->server()->reset();
        $this->rig = $rig;
        $this->apply();
    }

    // A fault a step sets before the boot waits for it: the migration goes through.
    public function failWith(?\Throwable $error = null): void
    {
        if ($error === null) {
            $this->refusing = true;
        } else {
            $this->error = $error;
        }
        if ($this->rig?->booted()) {
            $this->apply();
        }
    }

    public function failures(): array
    {
        return [];
    }

    public function release(): void
    {
        $this->rig = null;
        $this->refusing = false;
        $this->error = null;
    }

    private function apply(): void
    {
        $databases = $this->rig?->databases();
        if ($this->refusing) {
            $databases?->refuse($this->server()->address, $this->server()->refusing());
        }
        if ($this->error !== null) {
            $databases?->fail($this->server()->address, $this->error);
        }
    }

    private function server(): Server
    {
        return $this->server ?? throw new \LogicException('postgres is not provisioned: the service has not booted');
    }
}
