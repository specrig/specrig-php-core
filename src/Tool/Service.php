<?php

declare(strict_types=1);

namespace Specrig\Tool;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\Yaml\Yaml;

final class Service
{
    public const LAYOUT = [
        'generated' => ['tests/surface.yaml', 'tests/checks.yaml'],
        'platform' => ['tests/internal/', 'tests/behat.php', 'specrig.yaml', 'AGENTS.md', 'CLAUDE.md'],
        'cases' => ['tests/features/**/*.feature'],
        'tests' => ['tests/'],
    ];

    public const GUARD = ['php', 'vendor/bin/specrig'];

    public readonly string $root;

    /** @var array<string, mixed> */
    public readonly array $composer;

    /** @var array<string, mixed> */
    public readonly array $config;

    public function __construct(string $root)
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException("{$root} is no directory");
        }
        $this->root = $resolved;
        $composer = is_file("{$resolved}/composer.json") ? json_decode((string) file_get_contents("{$resolved}/composer.json"), true) : null;
        $this->composer = is_array($composer) ? $composer : [];
        $config = is_file("{$resolved}/specrig.yaml") ? Yaml::parseFile("{$resolved}/specrig.yaml") : null;
        $this->config = is_array($config) ? $config : [];
    }

    /** @return list<Area> */
    public static function defaults(): array
    {
        return [
            new Area('composer', ['composer.json', 'composer.lock'], ['platform'], ['redgreen']),
            new Area('flows', ['tests/flows.yaml'], ['spec', 'redgreen', 'platform']),
            new Area('steps', ['tests/steps/'], null, ['platform']),
        ];
    }

    // The service's own areas replace a default of the same name.
    /** @return list<Area> */
    public function areas(): array
    {
        $areas = [];
        foreach (self::defaults() as $area) {
            $areas[$area->name] = $area;
        }
        $declared = $this->config['agents']['areas'] ?? [];
        if (!is_array($declared) || ($declared !== [] && array_is_list($declared))) {
            throw new \InvalidArgumentException('agents.areas is not a mapping of areas by name');
        }
        foreach ($declared as $name => $area) {
            $areas[(string) $name] = Area::of((string) $name, $area);
        }
        foreach ($areas as $area) {
            foreach ([...$area->modes ?? [], ...$area->grant] as $mode) {
                try {
                    Modes::find($mode);
                } catch (\InvalidArgumentException $e) {
                    throw new \InvalidArgumentException("agents.areas.{$area->name}: {$e->getMessage()}");
                }
            }
        }
        return array_values($areas);
    }

    /** @return array<string, mixed> rules/1 */
    public function rules(): array
    {
        return [
            'specrig' => 'rules/1',
            'modes' => array_map(fn (Mode $m) => [
                'name' => $m->name,
                'opens' => $m->opens,
                ...($m->includes === [] ? [] : ['includes' => $m->includes]),
                ...($m->offered ? [] : ['offered' => false]),
                'allows' => Modes::allows($m),
                'brief' => $m->brief,
            ], Modes::all()),
            'classes' => array_map(fn (array $c) => ['name' => $c['name'], 'patterns' => [...$c['patterns'], ...self::LAYOUT[$c['name']] ?? []]], Modes::classes()),
            'areas' => array_map(fn (Area $a) => [
                'name' => $a->name,
                'paths' => $a->paths,
                ...($a->modes === null ? [] : ['modes' => $a->modes]),
                ...($a->grant === [] ? [] : ['grant' => $a->grant]),
            ], $this->areas()),
        ];
    }

    public function framework(): string
    {
        $required = [...array_keys($this->composer['require'] ?? []), ...array_keys($this->composer['require-dev'] ?? [])];
        return match (true) {
            in_array('laravel/framework', $required, true) => 'laravel',
            in_array('symfony/framework-bundle', $required, true) => 'symfony',
            default => throw new \InvalidArgumentException("{$this->root} is neither a Laravel nor a Symfony service: composer.json requires neither laravel/framework nor symfony/framework-bundle"),
        };
    }

    // The harness names the service in its records; a service with none yet goes by
    // its package's name.
    public function name(): string
    {
        $harness = "{$this->root}/tests/internal/Harness.php";
        if (is_file($harness)) {
            $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse((string) file_get_contents($harness)) ?? [];
            $method = (new NodeFinder())->findFirst($nodes, fn (Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === 'service');
            $returned = $method === null ? null : (new NodeFinder())->findFirstInstanceOf([$method], Node\Stmt\Return_::class)?->expr;
            if ($returned instanceof Node\Scalar\String_) {
                return $returned->value;
            }
        }
        $named = (string) ($this->composer['name'] ?? basename($this->root));
        $name = str_contains($named, '/') ? substr($named, strrpos($named, '/') + 1) : $named;
        return in_array($name, ['laravel', 'skeleton', 'symfony'], true) ? strtolower(basename($this->root)) : strtolower($name);
    }

    /** @return array<string, string> */
    public function namespaces(bool $dev = false): array
    {
        $mapped = [];
        foreach ($this->composer[$dev ? 'autoload-dev' : 'autoload']['psr-4'] ?? [] as $namespace => $dirs) {
            foreach ((array) $dirs as $dir) {
                $mapped[(string) $namespace] = rtrim((string) $dir, '/');
            }
        }
        return $mapped;
    }

    public function clients(): array
    {
        return $this->config['flows']['clients'] ?? [];
    }
}
