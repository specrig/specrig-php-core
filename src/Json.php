<?php

declare(strict_types=1);

namespace Specrig;

// A JSON value as the records hold it: an object is a stdClass, so an empty object
// and an empty list stay apart, and a body that is not JSON is its text.
final class Json
{
    public static function decode(string $raw): mixed
    {
        if ($raw === '') {
            return null;
        }
        try {
            return json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $raw;
        }
    }

    public static function of(mixed $value): mixed
    {
        return $value === null ? null : json_decode(self::encode($value), false, 512, JSON_THROW_ON_ERROR);
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<mixed>|null */
    public static function array(mixed $node): mixed
    {
        return json_decode(self::encode($node), true, 512, JSON_THROW_ON_ERROR);
    }
}
