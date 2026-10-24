<?php

declare(strict_types=1);

namespace Specrig\Tool;

use Composer\InstalledVersions;
use Specrig\Strings;
use Specrig\Surface;
use Specrig\Surface\Walker;
use Symfony\Component\Dotenv\Dotenv;

final class Init
{
    private const VERSION = '^0.1';

    public const WRITTEN = ['created' => '+ specrig.md', 'rewritten' => '~ specrig.md', 'current' => '= specrig.md'];

    // What the official image does not carry compiled in.
    private const BUILT = ['ctype', 'curl', 'date', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'json', 'libxml', 'mbstring', 'mysqlnd', 'openssl',
        'pcre', 'pdo', 'pdo_sqlite', 'phar', 'posix', 'random', 'readline', 'reflection', 'session', 'simplexml', 'sodium', 'spl', 'sqlite3',
        'standard', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'zlib'];

    /**
     * @param list<string> $args
     * @return array{int, string}
     */
    public static function run(array $args, string $cwd): array
    {
        [$positional, $flags] = Cli::parse($args, ['--backend', '--contract', '--failure', '--service']);
        $service = new Service(self::resolve($positional[0] ?? '.', $cwd));
        $framework = $service->framework();
        $name = $flags['--service'][0] ?? $service->name();
        $surface = (new Walker($service, $name))->run();
        $plan = self::plan($service, $framework, $name, $surface, $flags);
        return [0, implode("\n", self::write($plan, $surface))];
    }

    public static function resolve(string $dir, string $cwd): string
    {
        return str_starts_with($dir, '/') ? $dir : "{$cwd}/{$dir}";
    }

    /** @param array<string, list<string>> $flags */
    private static function plan(Service $service, string $framework, string $name, Surface $surface, array $flags): Plan
    {
        $read = [...array_keys($surface->settings), ...array_keys($surface->optional)];
        $contracts = self::pairs($flags['--contract'] ?? [], '--contract');
        $failures = self::pairs($flags['--failure'] ?? [], '--failure');
        $backends = [];
        foreach ($flags['--backend'] ?? [] as $given) {
            [$backend, $setting] = array_pad(explode('=', $given, 2), 2, null);
            if ($setting !== null && !in_array($setting, $read, true)) {
                $reads = $read === [] ? 'nothing' : implode(', ', Strings::sort($read));
                throw new \InvalidArgumentException("{$name} reads no setting {$setting}; it reads {$reads}");
            }
            $backends[] = [
                'name' => $backend,
                'property' => self::camel($backend),
                'origin' => "https://{$backend}.{$name}.test",
                'setting' => $setting,
                'contract' => $contracts[$backend] ?? null,
                'failure' => $failures[$backend] ?? null,
            ];
        }
        foreach ([...array_keys($contracts), ...array_keys($failures)] as $named) {
            if (!in_array($named, array_column($backends, 'name'), true)) {
                throw new \InvalidArgumentException("{$named} is not a backend; name it with --backend first");
            }
        }
        if ($backends === [] && $surface->calls !== []) {
            throw new \InvalidArgumentException("{$name} calls " . implode(', ', array_keys($surface->calls)) . ': name the neighbour with --backend <name>[=<setting>]');
        }

        $resources = self::resources($service, $framework, $read);
        $answered = [...array_filter(array_column($backends, 'setting')), ...array_column($resources, 'setting')];
        $constants = [];
        if ($framework === 'laravel') {
            $constants['APP_KEY'] = 'base64:' . base64_encode(random_bytes(32));
            if (array_filter($resources, fn (array $r) => $r['kind'] === 'Specrig\Postgres') !== []) {
                $constants['DB_CONNECTION'] = 'pgsql';
            }
        } else {
            $shipped = self::shipped($service);
            foreach (array_keys($surface->settings) as $setting) {
                if (!in_array($setting, $answered, true) && !in_array($setting, $shipped, true)) {
                    $constants[$setting] = '';
                }
            }
        }

        return new Plan(
            $service,
            $name,
            $framework,
            self::namespace($service),
            $backends,
            $resources,
            $constants,
            array_keys($surface->consumers),
            self::php($service),
            self::extensions($service, $resources),
            self::checkout() === null ? null : self::relative($service->root, (string) self::checkout()),
        );
    }

    /**
     * @param list<string> $read
     * @return list<array{kind: string, property: string, setting: string}>
     */
    private static function resources(Service $service, string $framework, array $read): array
    {
        $env = self::dotenv($service, $framework === 'laravel' ? ['.env.example'] : ['.env', '.env.prod']);
        $required = array_keys($service->composer['require'] ?? []);
        $resources = [];
        if ($framework === 'laravel' && ($env['DB_CONNECTION'] ?? null) === 'pgsql' || $framework === 'symfony' && str_starts_with((string) ($env['DATABASE_URL'] ?? ''), 'postgres')) {
            $resources[] = ['kind' => 'Specrig\Postgres', 'property' => 'database', 'setting' => $framework === 'laravel' ? 'DB_URL' : 'DATABASE_URL'];
        }
        $redis = $framework === 'laravel'
            ? array_intersect(['CACHE_STORE', 'QUEUE_CONNECTION', 'SESSION_DRIVER'], array_keys(array_filter($env, fn ($v) => $v === 'redis'))) !== []
            : in_array('REDIS_URL', $read, true);
        if ($redis) {
            $resources[] = ['kind' => 'Specrig\Redis', 'property' => 'cache', 'setting' => 'REDIS_URL'];
        }
        if (in_array('php-amqplib/php-amqplib', $required, true)) {
            $setting = array_values(array_filter($read, fn (string $s) => preg_match('/RABBIT|AMQP/', $s) === 1))[0] ?? null;
            if ($setting !== null) {
                $resources[] = ['kind' => 'Specrig\Amqp\Bus', 'property' => 'bus', 'setting' => $setting];
            }
        }
        return $resources;
    }

    /**
     * @param list<string> $files
     * @return array<string, string>
     */
    private static function dotenv(Service $service, array $files): array
    {
        $values = [];
        foreach ($files as $file) {
            $path = "{$service->root}/{$file}";
            if (is_file($path)) {
                $values = [...$values, ...(new Dotenv())->parse((string) file_get_contents($path), $path)];
            }
        }
        return $values;
    }

    /** @return list<string> */
    private static function shipped(Service $service): array
    {
        return array_keys(self::dotenv($service, ['.env', '.env.dist', '.env.prod']));
    }

    private static function namespace(Service $service): string
    {
        foreach ($service->namespaces(dev: true) as $namespace => $dir) {
            if ($dir === 'tests') {
                return $namespace;
            }
        }
        return 'Specs\\';
    }

    private static function php(Service $service): string
    {
        $wanted = (string) ($service->composer['require']['php'] ?? '^8.2');
        return preg_match('/^~\s*(8\.[234])\.\d/', $wanted, $exact) ? $exact[1] : '8.4';
    }

    /**
     * @param list<array{kind: string, property: string, setting: string}> $resources
     * @return list<string>
     */
    private static function extensions(Service $service, array $resources): array
    {
        $wanted = ['zip' => true];
        $lock = is_file("{$service->root}/composer.lock") ? json_decode((string) file_get_contents("{$service->root}/composer.lock"), true) : [];
        foreach ([$service->composer, ...$lock['packages'] ?? [], ...$lock['packages-dev'] ?? []] as $package) {
            foreach (array_keys($package['require'] ?? []) as $required) {
                if (str_starts_with((string) $required, 'ext-')) {
                    $wanted[strtolower(substr((string) $required, 4))] = true;
                }
            }
        }
        if (array_filter($resources, fn (array $r) => $r['kind'] === 'Specrig\Postgres') !== []) {
            $wanted['pdo_pgsql'] = true;
        }
        $extensions = array_values(array_diff(array_keys($wanted), self::BUILT));
        sort($extensions);
        return $extensions;
    }

    /** @return list<string> */
    private static function write(Plan $plan, Surface $surface): array
    {
        $root = $plan->service->root;
        $files = [
            'tests/internal/Harness.php' => Templates::harness($plan),
            'tests/internal/boot.feature' => Templates::BOOT,
            'tests/internal/Dockerfile' => Templates::dockerfile($plan),
            'tests/behat.php' => Templates::behat($plan),
            'specrig.yaml' => Templates::config(),
        ];
        $lines = [];
        foreach ($files as $path => $body) {
            if (file_exists("{$root}/{$path}")) {
                $lines[] = "= {$path} — kept";
                continue;
            }
            if (!is_dir(dirname("{$root}/{$path}"))) {
                mkdir(dirname("{$root}/{$path}"), 0777, true);
            }
            file_put_contents("{$root}/{$path}", $body);
            $lines[] = "+ {$path}";
        }
        foreach (['tests/features', 'tests/steps'] as $dir) {
            if (!is_dir("{$root}/{$dir}")) {
                mkdir("{$root}/{$dir}", 0777, true);
            }
        }
        $lines[] = self::agentsMd($plan->service, $plan->name);
        $lines[] = self::WRITTEN[self::writeSpecrigMd($plan->service)];
        if (!file_exists("{$root}/CLAUDE.md") && !is_link("{$root}/CLAUDE.md")) {
            symlink('AGENTS.md', "{$root}/CLAUDE.md");
            $lines[] = '+ CLAUDE.md → AGENTS.md';
        }
        if (!str_contains((string) @file_get_contents("{$root}/specrig.yaml"), 'guard:')) {
            $lines[] = '  specrig.yaml names no guard: add ' . trim(Templates::config()) . ' for the modes to be enforced';
        }
        array_push($lines, ...Cli::rewrite($plan->service, $surface));
        array_push($lines, ...self::composer($plan));
        return $lines;
    }

    /** @return list<string> */
    private static function composer(Plan $plan): array
    {
        $file = "{$plan->service->root}/composer.json";
        $composer = json_decode((string) file_get_contents($file), true);
        $version = $plan->checkout === null ? self::VERSION : '@dev';
        $added = [];
        foreach (['behat/behat' => '^3.34 || ^4.0', 'webmozart/assert' => '^1.11 || ^2.0', "specrig/{$plan->framework}" => $version] as $package => $constraint) {
            if (!isset($composer['require'][$package]) && !isset($composer['require-dev'][$package])) {
                $composer['require-dev'][$package] = $constraint;
                $added[] = $package;
            }
        }
        if ($plan->namespace === 'Specs\\' && !isset($composer['autoload-dev']['psr-4']['Specs\\'])) {
            $composer['autoload-dev']['psr-4']['Specs\\'] = 'tests/';
            $added[] = 'autoload-dev Specs\\';
        }
        if ($plan->checkout !== null) {
            $url = "{$plan->checkout}/packages/*";
            if (!in_array($url, array_column($composer['repositories'] ?? [], 'url'), true)) {
                $composer['repositories'][] = ['type' => 'path', 'url' => $url, 'options' => ['symlink' => true]];
                $added[] = "the repository {$url}";
            }
        }
        if ($added === []) {
            return [];
        }
        file_put_contents($file, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        return ['~ composer.json: ' . implode(', ', $added), '  then: composer update behat/behat webmozart/assert ' . "specrig/{$plan->framework}" . ' --with-all-dependencies'];
    }

    /**
     * @param list<string> $given
     * @return array<string, string>
     */
    private static function pairs(array $given, string $flag): array
    {
        $pairs = [];
        foreach ($given as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) !== 2) {
                throw new \InvalidArgumentException("{$flag} wants <name>=<value>");
            }
            $pairs[$parts[0]] = $parts[1];
        }
        return $pairs;
    }

    private static function camel(string $name): string
    {
        return lcfirst(str_replace(' ', '', ucwords(preg_replace('/[^A-Za-z0-9]+/', ' ', $name))));
    }

    /** @return 'created'|'rewritten'|'current' */
    public static function writeSpecrigMd(Service $service): string
    {
        $version = InstalledVersions::isInstalled('specrig/specrig') ? (string) InstalledVersions::getPrettyVersion('specrig/specrig') : 'dev';
        $body = Templates::specrigMd($service->areas(), $version);
        $file = "{$service->root}/specrig.md";
        $before = is_file($file) ? (string) file_get_contents($file) : null;
        if ($before === $body) {
            return 'current';
        }
        file_put_contents($file, $body);
        return $before === null ? 'created' : 'rewritten';
    }

    private static function agentsMd(Service $service, string $name): string
    {
        $file = "{$service->root}/AGENTS.md";
        if (!is_file($file)) {
            file_put_contents($file, Templates::agentsStub($name, is_file(dirname($service->root) . '/AGENTS.md')));
            return '+ AGENTS.md';
        }
        $text = (string) file_get_contents($file);
        $left = Agents::verdict($text) === 'stale'
            ? "; it still carries Rule #11, which specrig.md holds now: keep the service's own sections in it and delete the rest"
            : '';
        if (Agents::references($text)) {
            return "= AGENTS.md — kept{$left}";
        }
        $gap = $text === '' ? '' : (str_ends_with($text, "\n") ? "\n" : "\n\n");
        file_put_contents($file, $text . $gap . Templates::reference() . "\n");
        return "~ AGENTS.md: the line that brings specrig.md in, at its end{$left}";
    }

    private static function checkout(): ?string
    {
        $checkout = realpath(__DIR__ . '/../../../..');
        return $checkout !== false && is_file("{$checkout}/packages/specrig/composer.json") && is_dir("{$checkout}/packages/laravel") ? $checkout : null;
    }

    private static function relative(string $from, string $to): string
    {
        $from = explode('/', trim($from, '/'));
        $to = explode('/', trim($to, '/'));
        if ($from[0] !== $to[0]) {
            return '/' . implode('/', $to);
        }
        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }
        $path = implode('/', [...array_fill(0, count($from), '..'), ...$to]);
        return $path === '' ? '.' : $path;
    }
}
