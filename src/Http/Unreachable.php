<?php

declare(strict_types=1);

namespace Specrig\Http;

final class Unreachable extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $timedOut = false)
    {
        parent::__construct($message);
    }
}
