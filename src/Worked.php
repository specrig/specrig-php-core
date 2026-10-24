<?php

declare(strict_types=1);

namespace Specrig;

final class Worked
{
    public function __construct(public readonly string $job, public readonly ?\Throwable $error = null)
    {
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
