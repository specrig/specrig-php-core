<?php

declare(strict_types=1);

namespace Specrig\Http;

use Specrig\Backend;
use Specrig\Json;
use Specrig\Trace;

final class Inbound
{
    /**
     * @param \Closure(Request): array{Response, ?string} $handle the answer, and the route it came from
     */
    public static function exchange(Trace $trace, Request $request, \Closure $handle): Response
    {
        $call = $trace->begin();
        $name = "{$request->method} {$request->path()}";
        $status = 500;
        $body = '';
        try {
            [$response, $route] = $handle($request);
            $name = $route === null ? $name : "{$request->method} {$route}";
            [$status, $body] = [$response->status, $response->body];
            return $response;
        } finally {
            $trace->end($call, 'in', 'http', $name, self::received($request), $status >= 400 ? self::refusal($status, $body) : null);
        }
    }

    // As the Go record names a route: parameters without their constraints or
    // defaults, an optional one as any other, one that takes the rest of the path *.
    /** @param list<string> $catchAll */
    public static function route(string $path, array $catchAll = []): string
    {
        $route = preg_replace_callback(
            '/\{([^}<?!=:]+)[^}]*\}/',
            fn (array $m) => in_array($m[1], $catchAll, true) ? '*' : "{{$m[1]}}",
            $path,
        );
        return '/' . implode('/', array_filter(explode('/', $route), fn (string $s) => $s !== ''));
    }

    private static function received(Request $request): mixed
    {
        if ($request->body !== '') {
            return Json::decode($request->body);
        }
        $query = Backend::parse($request->search());
        return $query === [] ? null : (object) $query;
    }

    // A framework's own problem types are links to the RFC, which name no refusal.
    private static function refusal(int $status, string $body): string
    {
        $refusal = "HTTP {$status}";
        $answer = Json::decode($body);
        $type = $answer instanceof \stdClass ? $answer->type ?? null : null;
        return is_string($type) && $type !== '' && !preg_match('/^[a-z][a-z0-9+.-]*:/i', $type) ? "{$refusal} {$type}" : $refusal;
    }
}
