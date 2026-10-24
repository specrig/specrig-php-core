<?php

declare(strict_types=1);

namespace Specrig\Flows;

final class Orphan
{
    public function __construct(public string $anchor, public ?string $description = null, public ?string $title = null)
    {
    }
}
