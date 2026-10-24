<?php

declare(strict_types=1);

namespace Specrig;

final class Record
{
    public function __construct(
        public readonly int $seq,
        public readonly string $dir,
        public readonly string $kind,
        public readonly string $name,
        public readonly string $step,
        public readonly mixed $request = null,
        public readonly ?string $error = null,
    ) {
    }
}
