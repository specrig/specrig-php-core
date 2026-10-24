<?php

declare(strict_types=1);

namespace Specrig\Behat;

use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Step\When;
use Specrig\Rig;

final class Checks implements Context
{
    public function __construct(private readonly Rig $harness)
    {
    }

    #[When('the service boots')]
    public function boots(): void
    {
        $this->harness->boot();
    }

    #[AfterScenario]
    public function check(): void
    {
        $failures = $this->harness->failures();
        if ($failures !== []) {
            throw new \RuntimeException(implode("\n", $failures));
        }
    }
}
