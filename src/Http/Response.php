<?php

declare(strict_types=1);

namespace Specrig\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function json(): mixed
    {
        return $this->body === '' ? null : json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }
}
