<?php

declare(strict_types=1);

namespace Specrig;

use Specrig\Http\Request;
use Specrig\Http\Response;

interface StandIn
{
    public function origin(): string;

    public function setting(): ?string;

    public function attach(string $name, Trace $trace, string $root): void;

    public function answer(Request $request): Response;

    /** @return list<string> */
    public function failures(): array;
}
