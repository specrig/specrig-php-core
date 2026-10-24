<?php

declare(strict_types=1);

namespace Specrig\Flows;

final class Input
{
    public ?string $description = null;

    /** @var list<string>|null */
    public ?array $fields = null;

    /** @var array<string, list<string>>|null */
    public ?array $needs = null;

    /** @var list<Branch> */
    public array $branches = [];

    public function __construct(public string $message)
    {
    }

    public function anchor(): string
    {
        return $this->message;
    }
}
