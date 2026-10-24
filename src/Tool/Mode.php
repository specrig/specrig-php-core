<?php

declare(strict_types=1);

namespace Specrig\Tool;

final class Mode
{
    /**
     * @param list<string> $opens
     * @param list<string> $includes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $opens,
        public readonly string $allows,
        public readonly string $brief,
        public readonly array $includes = [],
        public readonly bool $offered = true,
    ) {
    }
}
