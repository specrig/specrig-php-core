<?php

declare(strict_types=1);

namespace Specrig;

final class Run
{
    /** @param list<Record> $records */
    public function __construct(public readonly string $case, public readonly array $records)
    {
    }
}
