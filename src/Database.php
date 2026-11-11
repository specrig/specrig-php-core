<?php

declare(strict_types=1);

namespace Specrig;

use Specrig\Sql\Engine;

abstract class Database implements Resource
{
    private ?Engine $engine = null;

    private ?Rig $rig = null;

    private bool $refusing = false;

    private ?\Throwable $error = null;

    /** @var list<\Closure(Engine): void> */
    private array $waiting = [];

    /** @param list<string> $keep */
    public function __construct(private readonly string $setting, private readonly array $keep = [])
    {
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
        $this->engine = $this->start(preg_replace('/[^a-z0-9]/', '_', strtolower($service)));
        return $this->engine->address;
    }

    public function ready(Rig $rig): void
    {
        $databases = $rig->databases();
        $this->engine()->once(fn () => $databases->migrate(), [...$this->keep, ...$databases->bookkeeping()]);
        $this->engine()->reset();
        $this->rig = $rig;
        $this->apply();
        foreach ($this->waiting as $refusal) {
            $refusal($this->engine());
        }
        $this->waiting = [];
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

    public function refuse(string $table, Write ...$writes): void
    {
        $this->refused(fn (Engine $engine) => $engine->refuse($table, 0, $writes));
    }

    public function refuseAfter(string $table, int $rows, Write ...$writes): void
    {
        $this->refused(fn (Engine $engine) => $engine->refuse($table, $rows, $writes));
    }

    public function heal(): void
    {
        $rig = $this->rig;
        $this->refusing = false;
        $this->error = null;
        $this->waiting = [];
        if ($rig?->booted()) {
            $rig->databases()->heal($this->engine()->address, $this->engine()->refusing());
            $this->engine()->lift();
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
        $this->waiting = [];
    }

    // The run's own, one per service: provisioning costs, a case does not.
    abstract protected function start(string $service): Engine;

    /** @param \Closure(Engine): void $refusal */
    protected function refused(\Closure $refusal): void
    {
        if ($this->rig?->booted()) {
            $refusal($this->engine());
        } else {
            $this->waiting[] = $refusal;
        }
    }

    private function apply(): void
    {
        $databases = $this->rig?->databases();
        if ($this->refusing) {
            $databases?->refuse($this->engine()->address, $this->engine()->refusing());
        }
        if ($this->error !== null) {
            $databases?->fail($this->engine()->address, $this->error);
        }
    }

    private function engine(): Engine
    {
        return $this->engine ?? throw new \LogicException(strtolower((new \ReflectionClass($this))->getShortName()) . ' is not provisioned: the service has not booted');
    }
}
