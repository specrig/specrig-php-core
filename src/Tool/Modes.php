<?php

declare(strict_types=1);

namespace Specrig\Tool;

use Symfony\Component\Yaml\Yaml;

final class Modes
{
    private const FILE = __DIR__ . '/../../protocol/modes.yaml';

    /** @var array{list<Mode>, list<array{name: string, patterns: list<string>}>}|null */
    private static ?array $table = null;

    /** @return list<Mode> */
    public static function all(): array
    {
        return self::table()[0];
    }

    /** @return list<array{name: string, patterns: list<string>}> */
    public static function classes(): array
    {
        return self::table()[1];
    }

    public static function allows(Mode $mode): string
    {
        return str_replace('{{cases}}', '`' . implode('`, `', Service::LAYOUT['cases']) . '`', $mode->allows);
    }

    public static function find(string $name): Mode
    {
        foreach (self::all() as $mode) {
            if ($mode->name === $name) {
                return $mode;
            }
        }
        $have = implode(', ', array_map(fn (Mode $m) => $m->name, self::all()));
        throw new \InvalidArgumentException("unknown mode \"{$name}\" (have: {$have})");
    }

    /** @return array{list<Mode>, list<array{name: string, patterns: list<string>}>} */
    private static function table(): array
    {
        if (self::$table === null) {
            $data = Yaml::parseFile(self::FILE);
            if (($data['specrig'] ?? null) !== 'modes/1') {
                throw new \UnexpectedValueException('modes.yaml is ' . ($data['specrig'] ?? 'unversioned') . ', and this tool reads modes/1');
            }
            self::$table = [
                array_map(fn (array $m) => new Mode($m['name'], $m['opens'], $m['allows'], $m['brief'], $m['includes'] ?? [], $m['offered'] ?? true), $data['modes']),
                array_map(fn (array $c) => ['name' => $c['name'], 'patterns' => $c['patterns'] ?? []], $data['classes']),
            ];
        }
        return self::$table;
    }
}
