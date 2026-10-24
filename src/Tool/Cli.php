<?php

declare(strict_types=1);

namespace Specrig\Tool;

use Specrig\Surface;
use Specrig\Surface\Walker;

final class Cli
{
    private const USAGE = <<<'USAGE'
        specrig init [dir] --backend <name>[=<setting>] [--contract <name>=<path>] [--failure <name>=<schema>] [--service <name>]
        specrig surface [dir] [--service <name>]
        specrig agents [dir]
        specrig rules

        init lays the harness out beside a service; surface writes tests/surface.yaml and
        tests/checks.yaml from the service's code; agents writes specrig.md, Rule #11 for
        the service, and fails while AGENTS.md does not bring it in; rules prints the
        service's Rule #11 rules, rules/1, in its directory, for the plugin that enforces
        the modes.
        USAGE;

    /** @param list<string> $args */
    public static function run(array $args, string $cwd): int
    {
        try {
            [$code, $out] = match ($args[0] ?? '') {
                'init' => Init::run(array_slice($args, 1), $cwd),
                'surface' => self::surface(array_slice($args, 1), $cwd),
                'agents' => self::agents(array_slice($args, 1), $cwd),
                'rules' => count($args) === 1 ? [0, self::rulesOf(new Service($cwd))] : [2, self::USAGE],
                '', '-h', '--help', 'help' => [2, self::USAGE],
                default => [2, 'Unknown command "' . $args[0] . "\"\n" . self::USAGE],
            };
        } catch (\InvalidArgumentException|\RuntimeException|\LogicException $e) {
            [$code, $out] = [1, "Error: {$e->getMessage()}"];
        }
        if ($out !== '') {
            fwrite($code === 0 ? STDOUT : STDERR, rtrim($out) . "\n");
        }
        return $code;
    }

    public static function rulesOf(Service $service): string
    {
        return json_encode($service->rules(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $args
     * @return array{int, string}
     */
    private static function surface(array $args, string $cwd): array
    {
        [$positional, $flags] = self::parse($args, ['--service']);
        $service = new Service(Init::resolve($positional[0] ?? '.', $cwd));
        $surface = (new Walker($service, $flags['--service'][0] ?? $service->name()))->run();
        return [0, implode("\n", self::rewrite($service, $surface))];
    }

    /**
     * @param list<string> $args
     * @return array{int, string}
     */
    public static function agents(array $args, string $cwd): array
    {
        [$positional] = self::parse($args, []);
        $service = new Service(Init::resolve($positional[0] ?? '.', $cwd));
        $file = "{$service->root}/AGENTS.md";
        if (!is_file($file)) {
            throw new \InvalidArgumentException('there is no AGENTS.md here: `php vendor/bin/specrig init` writes one that brings specrig.md in');
        }
        $written = Init::WRITTEN[Init::writeSpecrigMd($service)];
        return match (Agents::verdict((string) file_get_contents($file))) {
            'stale' => [1, "{$written}\nError: AGENTS.md still carries Rule #11, which specrig.md holds now: keep the service's own sections in it and delete the rest"],
            'unreferenced' => [1, "{$written}\nError: AGENTS.md does not bring specrig.md in: add the line\n\n" . Templates::reference() . "\n\nwhere an agent reads it before the first edit"],
            default => [0, $written],
        };
    }

    /** @return list<string> */
    public static function rewrite(Service $service, Surface $surface): array
    {
        $lines = [];
        foreach (['tests/surface.yaml' => $surface->encode(), 'tests/checks.yaml' => $surface->encodeChecks()] as $file => $body) {
            $lines[] = (Surface::rewrite("{$service->root}/{$file}", $body) ? '~ ' : '= ') . $file;
        }
        return $lines;
    }

    /**
     * @param list<string> $args
     * @param list<string> $known
     * @return array{list<string>, array<string, list<string>>}
     */
    public static function parse(array $args, array $known): array
    {
        $positional = [];
        $flags = [];
        for ($i = 0; $i < count($args); $i++) {
            if (in_array($args[$i], $known, true)) {
                if (!isset($args[$i + 1])) {
                    throw new \InvalidArgumentException("{$args[$i]} needs a value");
                }
                $flags[$args[$i]][] = $args[++$i];
            } elseif (str_starts_with($args[$i], '--')) {
                throw new \InvalidArgumentException("unknown argument {$args[$i]}");
            } else {
                $positional[] = $args[$i];
            }
        }
        return [$positional, $flags];
    }
}
