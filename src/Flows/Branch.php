<?php

declare(strict_types=1);

namespace Specrig\Flows;

final class Branch
{
    /** @var array<string, list<string>>|null */
    public ?array $sent = null;

    public ?string $description = null;

    /** @var list<string> */
    public array $cases = [];

    /** @param list<string>|null $sends */
    public function __construct(public ?array $sends = null, public ?string $refused = null)
    {
    }

    public function anchorIn(Input $input): string
    {
        return "{$input->message} -> " . implode(', ', $this->sends ?? []) . ($this->refused === null ? '' : " (refused {$this->refused})");
    }
}
