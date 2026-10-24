<?php

declare(strict_types=1);

namespace Specrig\Amqp;

use Specrig\Json;

final class Message
{
    /** @param array<string, mixed> $headers */
    public function __construct(
        public readonly string $exchange,
        public readonly string $routingKey,
        public readonly array $headers,
        public readonly string $bytes,
    ) {
    }

    public function json(): mixed
    {
        return Json::decode($this->bytes);
    }

    /** @return array<mixed> */
    public function body(): array
    {
        $body = json_decode($this->bytes, true);
        if (!is_array($body)) {
            throw new \UnexpectedValueException("{$this->routingKey} carried no body");
        }
        return $body;
    }
}
