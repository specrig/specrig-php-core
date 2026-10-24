<?php

declare(strict_types=1);

namespace Specrig\Surface;

final class Clients
{
    private const LARAVEL = ['Illuminate\Http\Client\PendingRequest', 'Illuminate\Http\Client\Factory'];

    private const GUZZLE = ['GuzzleHttp\Client', 'GuzzleHttp\ClientInterface'];

    private const SYMFONY = ['Symfony\Contracts\HttpClient\HttpClientInterface'];

    private const VERBS = ['get' => 'GET', 'head' => 'HEAD', 'post' => 'POST', 'put' => 'PUT', 'patch' => 'PATCH', 'delete' => 'DELETE'];

    /** @return list<string>|null */
    public static function facade(string $class): ?array
    {
        return match (ltrim($class, '\\')) {
            'Illuminate\Support\Facades\Http', 'Http' => [self::LARAVEL[0]],
            'Symfony\Component\HttpClient\HttpClient' => self::SYMFONY,
            default => null,
        };
    }

    public static function fluent(string $class, string $method): bool
    {
        $method = strtolower($method);
        return match (true) {
            in_array($class, self::LARAVEL, true) => !isset(self::VERBS[$method]) && !in_array($method, ['send', 'pool', 'async'], true),
            in_array($class, self::SYMFONY, true) => $method === 'withoptions',
            default => false,
        };
    }

    // The verb, or the argument that carries it, and the argument that carries the address.
    /** @return array{?string, ?int, int}|null */
    public static function request(string $class, string $method): ?array
    {
        $method = strtolower($method);
        $laravel = in_array($class, self::LARAVEL, true) || self::facade($class) === [self::LARAVEL[0]];
        if ($laravel || in_array($class, self::GUZZLE, true)) {
            $verb = self::VERBS[$method] ?? (in_array($class, self::GUZZLE, true) ? self::VERBS[substr($method, 0, -5)] ?? null : null);
            if ($verb !== null && ($laravel ? !str_ends_with($method, 'async') : true)) {
                return [$verb, null, 0];
            }
            if ($method === 'send' && $laravel || in_array($method, ['request', 'requestasync'], true) && !$laravel) {
                return [null, 0, 1];
            }
        }
        if (in_array($class, self::SYMFONY, true) && $method === 'request') {
            return [null, 0, 1];
        }
        return null;
    }
}
