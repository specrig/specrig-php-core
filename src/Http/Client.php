<?php

declare(strict_types=1);

namespace Specrig\Http;

use Specrig\Json;

final class Client
{
    /**
     * @param \Closure(Request): Response $handle
     * @param array<string, string> $headers
     */
    public function __construct(private readonly \Closure $handle, private readonly array $headers = ['Accept' => 'application/json'])
    {
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->handle, $headers + $this->headers);
    }

    /** @param array<string, string> $headers */
    public function get(string $uri, array $headers = []): Response
    {
        return $this->send('GET', $uri, null, $headers);
    }

    /** @param array<string, string> $headers */
    public function delete(string $uri, array $headers = []): Response
    {
        return $this->send('DELETE', $uri, null, $headers);
    }

    /** @param array<string, string> $headers */
    public function post(string $uri, mixed $body = null, array $headers = []): Response
    {
        return $this->send('POST', $uri, $body, $headers);
    }

    /** @param array<string, string> $headers */
    public function put(string $uri, mixed $body = null, array $headers = []): Response
    {
        return $this->send('PUT', $uri, $body, $headers);
    }

    /** @param array<string, string> $headers */
    public function patch(string $uri, mixed $body = null, array $headers = []): Response
    {
        return $this->send('PATCH', $uri, $body, $headers);
    }

    /** @param array<string, string> $headers */
    public function send(string $method, string $uri, mixed $body = null, array $headers = []): Response
    {
        $headers += $this->headers;
        if ($body !== null && !is_string($body)) {
            $body = Json::encode($body);
            $headers += ['Content-Type' => 'application/json'];
        }
        $url = preg_match('#^[a-z][a-z0-9+.-]*://#i', $uri) ? $uri : 'http://localhost/' . ltrim($uri, '/');
        return ($this->handle)(new Request(strtoupper($method), $url, $headers, $body ?? ''));
    }
}
