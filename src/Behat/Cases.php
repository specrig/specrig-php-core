<?php

declare(strict_types=1);

namespace Specrig\Behat;

use Specrig\Features;
use Specrig\Flows;
use Specrig\Rig;
use Specrig\Run;
use Specrig\Scenario;

final class Cases
{
    private ?Rig $current = null;

    private ?string $service = null;

    /** @var list<Run> */
    private array $runs = [];

    /** @var array<string, true> */
    private array $ran = [];

    /** @param class-string<Rig> $harness */
    public function __construct(private readonly string $harness, private readonly string $tests, private readonly string $features)
    {
    }

    /** @return class-string<Rig> */
    public function harness(): string
    {
        return $this->harness;
    }

    public function current(): Rig
    {
        if ($this->current === null) {
            if (!is_subclass_of($this->harness, Rig::class)) {
                throw new \LogicException("{$this->harness}, the harness tests/behat.php names, is no specrig harness");
            }
            $this->current = new ($this->harness)();
        }
        return $this->current;
    }

    public function running(): ?Rig
    {
        return $this->current;
    }

    public function ended(string $file, string $title): void
    {
        $current = $this->current;
        $this->current = null;
        if ($current === null) {
            return;
        }
        try {
            $features = rtrim($this->features, '/') . '/';
            if (str_starts_with($file, $features)) {
                $label = substr($file, strlen($features)) . ": {$title}";
                $this->runs[] = new Run($label, $current->trace->records());
                $this->ran[$label] = true;
                $this->service ??= $current->service();
            }
        } finally {
            $current->dispose();
        }
    }

    // A run narrowed to some cases saw part of the service, and its record would lose the rest.
    public function write(): void
    {
        $cases = array_filter((new Features($this->features))->cases(), fn (Scenario $c) => !$c->ignored);
        if ($this->service === null || array_filter($cases, fn (Scenario $c) => !isset($this->ran[$c->label()])) !== []) {
            return;
        }
        if (Flows::write("{$this->tests}/flows.yaml", $this->service, $this->runs) > 0) {
            throw new \RuntimeException(
                'tests/flows.yaml: some descriptions lost their anchor — delete them or move them under the input or branch they belong to now',
            );
        }
    }
}
