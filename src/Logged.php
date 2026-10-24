<?php

declare(strict_types=1);

namespace Specrig;

final class Logged
{
    /** @param array<string, mixed> $context */
    public function __construct(public readonly string $level, public readonly string $message, public readonly array $context = [])
    {
    }
}
