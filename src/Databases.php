<?php

declare(strict_types=1);

namespace Specrig;

interface Databases
{
    public function migrate(): void;

    /** @return list<string> */
    public function bookkeeping(): array;

    public function refuse(string $address, string $refusing): void;

    public function fail(string $address, \Throwable $error): void;
}
