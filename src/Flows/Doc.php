<?php

declare(strict_types=1);

namespace Specrig\Flows;

use Specrig\Flows;

final class Doc
{
    public string $specrig = Flows::FORMAT;

    /** @var list<Input> */
    public array $inputs = [];

    /** @var list<Output>|null */
    public ?array $outputs = null;

    /** @var list<Orphan>|null */
    public ?array $orphaned = null;

    public function __construct(public string $service = '')
    {
    }
}
