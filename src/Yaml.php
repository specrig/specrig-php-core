<?php

declare(strict_types=1);

namespace Specrig;

use Symfony\Component\Yaml\Yaml as Parser;

// A mapping is an array with string keys, a sequence a list; an entry whose value
// is null or empty is left out, as omitempty does in the Go record.
final class Yaml
{
    private const WHITE = '\x{9}-\x{D}\x{20}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';

    private const CONTROL = '\x{0}-\x{1F}\x{7F}-\x{9F}';

    /** @param array<string, mixed> $root */
    public static function emit(array $root): string
    {
        return self::write($root, 0);
    }

    /** @param array<string, mixed> $mapping */
    private static function write(array $mapping, int $indent): string
    {
        $pad = str_repeat(' ', $indent);
        $text = '';
        foreach ($mapping as $key => $value) {
            $key = (string) $key;
            if ($value === null || $value === []) {
                continue;
            }
            if (is_string($value)) {
                $text .= $pad . $key . ': ' . self::scalar($value, $indent + 2) . "\n";
            } elseif (is_array($value) && !array_is_list($value)) {
                $text .= $pad . $key . ":\n" . self::write($value, $indent + 2);
            } elseif (is_array($value) && is_string($value[0])) {
                $text .= $pad . $key . ":\n";
                foreach ($value as $item) {
                    $text .= $pad . '  - ' . self::scalar($item, $indent + 4) . "\n";
                }
            } elseif (is_array($value) && is_array($value[0])) {
                $text .= $pad . $key . ":\n";
                foreach ($value as $item) {
                    $text .= $pad . '  - ' . substr(self::write($item, $indent + 4), $indent + 4);
                }
            } else {
                throw new \InvalidArgumentException("{$key}: no YAML for a " . get_debug_type($value));
            }
        }
        return $text;
    }

    private static function scalar(string $value, int $indent): string
    {
        if (self::plain($value)) {
            return $value;
        }
        if (!str_contains($value, "\n") || str_contains($value, "\r") || str_starts_with($value, ' ') || str_ends_with($value, "\n\n")) {
            return self::quoted($value);
        }
        $clip = str_ends_with($value, "\n");
        $pad = str_repeat(' ', $indent);
        $text = $clip ? '|' : '|-';
        foreach (explode("\n", $clip ? substr($value, 0, -1) : $value) as $line) {
            $text .= $line === '' ? "\n" : "\n" . $pad . $line;
        }
        return $text;
    }

    private static function plain(string $value): bool
    {
        return $value !== ''
            && !preg_match('/^[' . self::WHITE . ']|[' . self::WHITE . ']$/u', $value)
            && strpbrk($value[0], "-?:,[]{}#&*!|>'\"%@`") === false
            && !str_contains($value, ': ')
            && !str_contains($value, ' #')
            && !str_ends_with($value, ':')
            && !preg_match('/[' . self::CONTROL . ']/u', $value)
            && !preg_match('/^(?:true|false|yes|no|on|off|y|n|null|~)$/i', $value)
            && !preg_match('/^[-+]?(\.?[0-9]|0x|0o|\.inf|\.nan)/i', $value);
    }

    private static function quoted(string $value): string
    {
        $text = '"';
        foreach (mb_str_split($value) as $c) {
            $text .= match (true) {
                $c === '"' => '\\"',
                $c === '\\' => '\\\\',
                $c === "\n" => '\\n',
                $c === "\t" => '\\t',
                $c === "\r" => '\\r',
                (bool) preg_match('/^[' . self::CONTROL . ']$/u', $c) => sprintf('\\x%02x', mb_ord($c)),
                default => $c,
            };
        }
        return $text . '"';
    }

    // An OpenAPI document read as JSON: a plain scalar is typed as YAML types it, a
    // quoted one is a string, and a mapping is an object.
    public static function toJson(string $yaml): mixed
    {
        return Parser::parse($yaml, Parser::PARSE_OBJECT_FOR_MAP);
    }
}
