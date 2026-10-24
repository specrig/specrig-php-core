<?php

declare(strict_types=1);

namespace Specrig\Http;

final class Origin
{
    private const DEFAULT = ['http' => 80, 'https' => 443];

    public static function of(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException("{$url} is no absolute address");
        }
        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) && $parts['port'] !== (self::DEFAULT[$scheme] ?? null) ? ":{$parts['port']}" : '';
        return "{$scheme}://" . strtolower($parts['host']) . $port;
    }
}
