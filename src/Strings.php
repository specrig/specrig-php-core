<?php

declare(strict_types=1);

namespace Specrig;

// Sets of strings kept as an array's keys, which PHP turns into integers where it
// can, and lists sorted by bytes, as every record is.
final class Strings
{
    /** @param array<array-key, true> $set */
    public static function sorted(array $set): array
    {
        return self::sort(array_map('strval', array_keys($set)));
    }

    /**
     * @param list<string> $list
     * @return list<string>
     */
    public static function sort(array $list): array
    {
        usort($list, 'strcmp');
        return $list;
    }

    /**
     * @param array<array-key, mixed> $map
     * @return array<string, mixed>
     */
    public static function byKey(array $map): array
    {
        uksort($map, fn ($a, $b) => strcmp((string) $a, (string) $b));
        return $map;
    }
}
