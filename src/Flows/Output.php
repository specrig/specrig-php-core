<?php

declare(strict_types=1);

namespace Specrig\Flows;

final class Output
{
    /** @param list<string>|null $fields */
    public function __construct(public string $message, public ?array $fields = null)
    {
    }
}
