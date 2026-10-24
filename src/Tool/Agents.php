<?php

declare(strict_types=1);

namespace Specrig\Tool;

final class Agents
{
    private const PARTS = ['header', 'contributing', 'short', 'rule11', 'reference'];

    private const HEADING = '## Rule #11 — specification before code';

    private const TOKEN = '@specrig.md';

    private const DIR = __DIR__ . '/../../protocol/agents';

    private const SLOT = '/\{\{([a-z-]+)\}\}/';

    /** @param array<string, string> $slots */
    public static function render(string $part, array $slots): string
    {
        if (!in_array($part, self::PARTS, true)) {
            throw new \InvalidArgumentException("no part \"{$part}\" (have: " . implode(', ', self::PARTS) . ')');
        }
        $unknown = self::unknown($slots);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('no part has the slots ' . implode(', ', $unknown));
        }
        $missing = [];
        $out = [];
        foreach (explode("\n", (string) file_get_contents(self::DIR . "/{$part}.md")) as $line) {
            if (preg_match(self::SLOT, $line, $m) && $m[0] === $line && ($slots[$m[1]] ?? '') === '') {
                if (!array_key_exists($m[1], $slots)) {
                    $missing[] = $m[1];
                }
                continue;
            }
            $out[] = preg_replace_callback(self::SLOT, function (array $m) use ($slots, &$missing) {
                if (!array_key_exists($m[1], $slots)) {
                    $missing[] = $m[1];
                }
                return $slots[$m[1]] ?? '';
            }, $line);
        }
        if ($missing !== []) {
            $missing = array_unique($missing);
            sort($missing);
            throw new \InvalidArgumentException("{$part} leaves " . implode(', ', $missing) . ' unfilled');
        }
        $text = implode("\n", $out);
        while (str_contains($text, "\n\n\n")) {
            $text = str_replace("\n\n\n", "\n\n", $text);
        }
        return $text;
    }

    /**
     * @param array<string, string> $slots
     * @return list<string>
     */
    public static function unknown(array $slots): array
    {
        $known = [];
        foreach (self::PARTS as $part) {
            preg_match_all(self::SLOT, (string) file_get_contents(self::DIR . "/{$part}.md"), $m);
            $known = [...$known, ...$m[1]];
        }
        $unknown = array_values(array_diff(array_keys($slots), $known));
        sort($unknown);
        return $unknown;
    }

    /** @return 'absent'|'stale'|'unreferenced'|'ok' */
    public static function verdict(?string $text): string
    {
        if ($text === null) {
            return 'absent';
        }
        [$stale, $referenced] = self::scan($text);
        return $stale ? 'stale' : ($referenced ? 'ok' : 'unreferenced');
    }

    public static function references(string $text): bool
    {
        return self::scan($text)[1];
    }

    /** @return array{bool, bool} */
    private static function scan(string $text): array
    {
        $fence = null;
        $stale = $referenced = false;
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) {
                if ($fence === null) {
                    $fence = $m[1];
                } elseif ($m[1][0] === $fence[0] && strlen($m[1]) >= strlen($fence)) {
                    $fence = null;
                }
                continue;
            }
            if ($fence !== null) {
                continue;
            }
            $stale = $stale || rtrim($line) === self::HEADING;
            $referenced = $referenced || self::standsAlone($line);
        }
        return [$stale, $referenced];
    }

    private static function standsAlone(string $line): bool
    {
        $code = self::codeSpans($line);
        for ($at = strpos($line, self::TOKEN); $at !== false; $at = strpos($line, self::TOKEN, $at + 1)) {
            $end = $at + strlen(self::TOKEN);
            $before = $at === 0 || ctype_space($line[$at - 1]);
            $after = $end === strlen($line) || ctype_space($line[$end]);
            $inCode = array_filter($code, fn (array $span) => $at >= $span[0] && $at < $span[1]) !== [];
            if ($before && $after && !$inCode) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{int, int}> */
    private static function codeSpans(string $line): array
    {
        preg_match_all('/`+/', $line, $runs, PREG_OFFSET_CAPTURE);
        $runs = $runs[0];
        $spans = [];
        for ($i = 0; $i < count($runs); $i++) {
            for ($j = $i + 1; $j < count($runs); $j++) {
                if (strlen($runs[$j][0]) === strlen($runs[$i][0])) {
                    $spans[] = [$runs[$i][1], $runs[$j][1] + strlen($runs[$j][0])];
                    $i = $j;
                    break;
                }
            }
        }
        return $spans;
    }

    public static function modeRows(): string
    {
        return implode("\n", array_map(fn ($mode) => "| {$mode->name} | " . Modes::allows($mode) . ' |', Modes::all()));
    }

    /** @param list<Area> $areas */
    public static function areaRows(array $areas): string
    {
        $rows = [];
        foreach ($areas as $area) {
            $paths = self::claimed($areas, $area);
            if ($paths === []) {
                continue;
            }
            $open = match (true) {
                $area->modes === null => 'as the table says',
                $area->modes === [] => 'no mode',
                default => implode(', ', $area->modes),
            };
            $granted = $area->grant === [] ? '—' : implode(', ', $area->grant);
            $rows[] = "| {$area->name} | `" . implode('`, `', $paths) . "` | {$open} | {$granted} |";
        }
        return implode("\n", $rows);
    }

    /**
     * @param list<Area> $areas
     * @return list<string>
     */
    private static function claimed(array $areas, Area $area): array
    {
        return array_values(array_filter($area->paths, fn (string $path) => array_filter(
            $areas,
            fn (Area $other) => $other->name < $area->name && in_array(self::names($path), array_map(self::names(...), $other->paths), true),
        ) === []));
    }

    private static function names(string $pattern): string
    {
        $names = [];
        foreach (explode('/', $pattern) as $name) {
            if ($name === '' || $name === '.') {
                continue;
            }
            if ($name === '..' && $names !== [] && end($names) !== '..') {
                array_pop($names);
                continue;
            }
            $names[] = $name;
        }
        return implode('/', $names);
    }
}
