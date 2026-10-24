<?php

declare(strict_types=1);

namespace Specrig\Http;

final class Request
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public function origin(): string
    {
        return Origin::of($this->url);
    }

    public function path(): string
    {
        return parse_url($this->url, PHP_URL_PATH) ?: '/';
    }

    public function search(): string
    {
        $query = parse_url($this->url, PHP_URL_QUERY);
        return $query === null || $query === false || $query === '' ? '' : "?{$query}";
    }
}
