<?php

declare(strict_types=1);

namespace Specrig\Tool;


final class Templates
{
    public static function harness(Plan $plan): string
    {
        $uses = ['Specrig\Backend' => $plan->backends !== []];
        $members = [];
        foreach ($plan->backends as $backend) {
            $named = ["'{$backend['origin']}'"];
            foreach (['setting', 'contract', 'failure'] as $key) {
                if ($backend[$key] !== null) {
                    $named[] = "{$key}: '{$backend[$key]}'";
                }
            }
            $members[] = "        public readonly Backend \${$backend['property']} = new Backend(" . implode(', ', $named) . '),';
        }
        foreach ($plan->resources as $resource) {
            $short = substr($resource['kind'], strrpos($resource['kind'], '\\') + 1);
            $uses[$resource['kind']] = true;
            $members[] = "        public readonly {$short} \${$resource['property']} = new {$short}('{$resource['setting']}'),";
        }
        $used = array_keys(array_filter($uses));
        sort($used);
        $imports = implode("\n", array_map(fn (string $class) => "use {$class};", $used));
        $constructor = $members === [] ? '' : "    public function __construct(\n" . implode("\n", $members) . "\n    ) {\n        parent::__construct();\n    }\n\n";
        $methods = ["    public function service(): string\n    {\n        return '{$plan->name}';\n    }"];
        if ($plan->constants !== []) {
            $entries = implode(', ', array_map(fn (string $k, string $v) => "'{$k}' => " . var_export($v, true), array_keys($plan->constants), $plan->constants));
            $methods[] = "    protected function constants(): array\n    {\n        return [{$entries}];\n    }";
        }
        if ($plan->running !== []) {
            $commands = implode(', ', array_map(fn (string $c) => "\\{$c}::class", $plan->running));
            $methods[] = "    protected function running(): array\n    {\n        return [{$commands}];\n    }";
        }
        $base = $plan->framework === 'laravel' ? '\Specrig\Laravel\Harness' : '\Specrig\Symfony\Harness';
        $kernel = self::kernel($plan);
        if ($kernel !== null) {
            $methods[] = "    protected function kernel(): string\n    {\n        return \\{$kernel}::class;\n    }";
        }
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$plan->namespace}Internal;\n\n"
            . ($imports === '' ? '' : "{$imports}\n\n")
            . "final class Harness extends {$base}\n{\n{$constructor}" . implode("\n\n", $methods) . "\n}\n";
    }

    // A Symfony service's kernel is App\Kernel unless its own code says otherwise.
    private static function kernel(Plan $plan): ?string
    {
        if ($plan->framework !== 'symfony') {
            return null;
        }
        foreach ($plan->service->namespaces() as $namespace => $dir) {
            if (is_file("{$plan->service->root}/{$dir}/Kernel.php")) {
                $kernel = "{$namespace}Kernel";
                return $kernel === 'App\Kernel' ? null : $kernel;
            }
        }
        return null;
    }

    public static function behat(Plan $plan): string
    {
        return <<<PHP
            <?php

            use Behat\\Config\\Config;
            use Behat\\Config\\Extension;
            use Behat\\Config\\Filter\\TagFilter;
            use Behat\\Config\\Profile;
            use Behat\\Config\\Suite;
            use Behat\\Config\\TesterOptions;
            use {$plan->namespace}Internal\\Harness;

            return (new Config())->withProfile((new Profile('default'))
                ->withTesterOptions((new TesterOptions())->withErrorReporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED))
                ->withExtension(new Extension(Specrig\\Behat\\Extension::class, ['harness' => Harness::class]))
                ->withSuite((new Suite('cases'))->withPaths('%paths.base%/features')->withFilter(new TagFilter('~@ignore')))
                ->withSuite((new Suite('platform'))->withPaths('%paths.base%/internal')));

            PHP;
    }

    public const BOOT = <<<'GHERKIN'
        Feature: The platform

          Scenario: The service boots with what the harness answers
            When the service boots

        GHERKIN;

    public static function dockerfile(Plan $plan): string
    {
        $extensions = implode(' ', $plan->extensions);
        return <<<DOCKER
            FROM php:{$plan->php}-cli
            COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
            COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
            RUN install-php-extensions {$extensions}

            DOCKER;
    }

    public static function config(): string
    {
        return 'guard: [' . implode(', ', Service::GUARD) . "]\n";
    }

    /** @param list<Area> $areas */
    public static function specrigMd(array $areas, string $version): string
    {
        $platform = '`' . implode('`, `', Service::LAYOUT['platform']) . '`';
        $slots = [
            'what' => 'service',
            'writer' => '`php vendor/bin/specrig agents`',
            'version' => "`specrig/specrig` {$version}",
            'cut' => '`tests/internal/Harness.php`',
            'validate' => '`php vendor/bin/behat -c tests/behat.php`',
            'boundary' => 'What a case may stand in for, and what the service is answered with when it boots, are the platform owner\'s whether the rule is in force or not: `tests/internal/Harness.php` says both, and a change there changes what every case means.',
            'spec-paths' => '`tests/features/`, `tests/steps/`',
            'platform-paths' => $platform,
            'tasks' => '',
            'modes' => Agents::modeRows(),
            'platform-files' => $platform,
            'generated' => '`tests/surface.yaml` and `tests/checks.yaml` are written by `php vendor/bin/specrig surface`, `specrig.md` by `php vendor/bin/specrig agents`, and the structure of `tests/flows.yaml` by every whole run of the suite, in any mode, and never by hand; its `description` fields are yours in any mode.',
            'tool' => 'specrig',
            'config' => '`specrig.yaml`',
            'areas' => Agents::areaRows($areas),
            'cases' => '`.feature` files in `tests/features/`, run by Behat',
            'contracts' => '**The contracts a case leans on are named in `tests/internal/Harness.php`,** beside the neighbour each holds. Every answer a step declares, and every body the service sends, is checked against them: a stand-in cannot answer what its neighbour cannot, and a route, a status or a body the contract does not have fails the case.',
            'describes' => "**A case describes what the service's callers see and what reaches its neighbours** — an answer, an event, a request to a neighbour — not its classes or its tables.\n\n**A neighbour answers what the case declared.** A number in a case comes from a fixture, never computed inside a step. A neighbour's error codes are contract, held by a case.",
            'binding' => self::BINDING,
        ];
        return Agents::render('header', $slots) . "\n"
            . Agents::render('contributing', $slots) . "\n"
            . "## The short version\n\n" . Agents::render('short', $slots) . "\n"
            . Agents::render('rule11', $slots) . "\n"
            . self::RUNNING;
    }

    public static function reference(): string
    {
        return rtrim(Agents::render('reference', ['what' => 'service']));
    }

    public static function agentsStub(string $name, bool $parentRules): string
    {
        $parent = $parentRules
            ? "The repository's rules in `../AGENTS.md` hold here too — ownership, the notation for\nspecifications, a neighbour's rules are not reproduced.\n\n"
            : '';
        return "# AGENTS.md\n\nInstructions for AI coding agents working in {$name}.\n\n" . self::reference() . "\n\n{$parent}"
            . "## This service\n\n<!-- What {$name} is for, who calls it, which neighbours it calls and what it\npublishes. Written by the person who owns the platform. -->\n";
    }

    private const BINDING = <<<'TEXT'
        **Bind at the service's edge, as its callers do.** A step sends a request through
        `$harness->http()`, delivers an event through the bus `Harness.php` holds, if it holds
        one — `$harness-><bus>->deliver()` — runs a job with `$harness->run()`, what the service
        queued with `$harness->work()`, or a command with `$harness->command()`. A `Given`
        writes fixtures through the service's own repositories, from `$harness->get()`; a
        `Then` reads back through them, not out of the answer.

        **The harness** is specrig, set up by `tests/internal/Harness.php`. A steps class in
        `tests/steps/` takes it in its constructor, with a property for each neighbour
        `Harness.php` names:

        - `$harness-><neighbour>->reply($method, $path, $status, $body)` — what the neighbour
          answers;
        - `$harness-><neighbour>->replyTo($method, $path, $query, $status, $body)` — the same,
          for a request whose query has these values;
        - `$harness-><neighbour>->timeOut($method, $path)` — the neighbour not answering in time;
        - `$harness-><neighbour>->requests($method, $path)` — what reached the neighbour, for a
          `Then`.

        A call to a neighbour no step answered fails the case, and so does a request to an
        origin `Harness.php` does not name — it is refused before it leaves.

        **What may be stood in for is given, not chosen.** `tests/internal/` is read-only. A
        case that needs another neighbour, or an answer the stand-in cannot give, is a stop:
        name the neighbour or the behaviour, and the assertion it would carry.

        **Time.** Set fixture dates relative to today — most cases need no clock. A case that
        needs a fixed date sets it in its own `Given` with `$harness->clock->set()`.

        **Assertions** are `Webmozart\Assert\Assert`: PHPUnit's need PHPUnit's runner to fail
        with a message.
        TEXT;

    private const RUNNING = <<<'TEXT'
        ## Running

        `php vendor/bin/behat -c tests/behat.php` runs the suite with the PHP and Composer on
        this machine, as an IDE runs it, and starts the resources it needs in Docker;
        `php vendor/bin/specrig surface` rewrites `tests/surface.yaml` and `tests/checks.yaml`
        from the code. For a machine without PHP, `tests/internal/Dockerfile` builds an image
        with the PHP and the extensions the service needs. A project that rewrites the records
        in CI and then runs `git diff --exit-code` sees a record that fell behind as a diff.

        `tests/flows.yaml` is what the cases make the service send: every `When` step's call is
        an input, and what left the service after it is what it sent. Only the `description`
        fields are written by hand: they survive a rewrite, and one whose step is gone moves to
        `orphaned` and fails the run until it is moved or deleted.

        TEXT;
}
