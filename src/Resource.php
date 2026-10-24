<?php

declare(strict_types=1);

namespace Specrig;

interface Resource
{
    public function setting(): string;

    /** @param \Closure(): void $boot */
    public function attach(Trace $trace, \Closure $boot): void;

    public function provision(string $service): string;

    public function ready(Rig $rig): void;

    /** @return list<string> */
    public function failures(): array;

    public function release(): void;
}
