<?php

declare(strict_types=1);

namespace Specrig;

final class Received
{
    /** @param array<string, list<string>> $query */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly mixed $json,
    ) {
    }

    /** @return array<mixed> */
    public function body(): array
    {
        $body = Json::array($this->json);
        if (!is_array($body)) {
            throw new \UnexpectedValueException("{$this->method} {$this->path} carried no body");
        }
        return $body;
    }
}
