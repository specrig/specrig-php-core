<?php

declare(strict_types=1);

namespace Specrig;

final class Scenario
{
    public function __construct(
        public readonly string $file,
        public readonly string $feature,
        public readonly string $title,
        public readonly bool $ignored,
    ) {
    }

    public function label(): string
    {
        return "{$this->file}: {$this->title}";
    }
}
