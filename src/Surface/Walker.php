<?php

declare(strict_types=1);

namespace Specrig\Surface;

use PhpParser\Node;
use PhpParser\Node\Expr;
use Specrig\Surface;
use Specrig\Tool\Service;

final class Walker
{
    private const SKIPPED = ['vendor', 'tests', 'var', 'storage', 'node_modules', 'cache', 'public'];

    private const COMMANDS = ['Illuminate\Console\Command', 'Symfony\Component\Console\Command\Command'];

    public function __construct(private readonly Service $service, private readonly string $name)
    {
    }

    public function run(): Surface
    {
        $framework = $this->service->framework();
        $code = new Code($this->files($framework));
        $surface = new Surface($this->name);
        $this->calls($code, $surface);
        $this->events($code, $surface);
        if ($framework === 'laravel') {
            (new Laravel($this->service, $code))->read($surface);
        } else {
            (new Symfony($this->service, $code))->read($surface);
        }
        return $surface;
    }

    /** @return list<string> */
    private function files(string $framework): array
    {
        $dirs = array_values($this->service->namespaces());
        array_push($dirs, ...($framework === 'laravel' ? ['routes', 'config', 'bootstrap'] : ['config']));
        $files = [];
        foreach (array_unique($dirs) as $dir) {
            $path = "{$this->service->root}/{$dir}";
            if (is_file($path) && str_ends_with($path, '.php')) {
                $files[] = $path;
            }
            if (!is_dir($path)) {
                continue;
            }
            $walk = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                fn (\SplFileInfo $file) => !$file->isDir() || !in_array($file->getFilename(), self::SKIPPED, true),
            ));
            foreach ($walk as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    }

    private function calls(Code $code, Surface $surface): void
    {
        foreach ($code->nodes(fn (Node $n) => ($n instanceof Expr\MethodCall || $n instanceof Expr\NullsafeMethodCall || $n instanceof Expr\StaticCall) && $n->name instanceof Node\Identifier) as [$call, $file]) {
            $types = $call instanceof Expr\StaticCall
                ? ($call->class instanceof Node\Name ? [$call->class->toString()] : [])
                : $code->types($call->var);
            foreach (array_unique($types) as $type) {
                $sent = Clients::request($type, $call->name->toString());
                if ($sent === null) {
                    continue;
                }
                [$verb, $verbAt, $urlAt] = $sent;
                $verbs = $verb !== null ? [$verb] : array_map('strtoupper', array_filter(
                    $code->templates(Code::argument($call, (int) $verbAt, 'method')),
                    fn (string $v) => !str_contains($v, Code::VARYING) && !str_contains($v, Code::UNCALLED),
                ));
                $key = Code::className($call) ?? substr($file, strlen($this->service->root) + 1);
                foreach ($code->templates(Code::argument($call, $urlAt, 'url') ?? Code::argument($call, $urlAt, 'uri')) as $template) {
                    if (str_starts_with($template, Code::UNCALLED)) {
                        continue;
                    }
                    foreach ($verbs as $method) {
                        $request = "{$method} " . self::path(str_replace(Code::UNCALLED, Code::VARYING, $template));
                        $surface->calls[$request] = true;
                        $surface->clients[$key][$request] = true;
                    }
                }
                break;
            }
        }
    }

    private function events(Code $code, Surface $surface): void
    {
        foreach ($code->nodes(fn (Node $n) => ($n instanceof Expr\MethodCall || $n instanceof Expr\NullsafeMethodCall) && $n->name instanceof Node\Identifier
            && in_array(strtolower($n->name->toString()), ['basic_publish', 'batch_basic_publish', 'queue_bind'], true)) as [$call, $file]) {
            $binds = strtolower($call->name->toString()) === 'queue_bind';
            foreach (self::named($code, Code::argument($call, 2, 'routing_key'), $call, $file, $binds ? 'consumes' : 'publishes') as $key) {
                if ($binds) {
                    $surface->consumes[$key] = true;
                } else {
                    $surface->publishes[$key] = true;
                }
            }
            $class = Code::className($call);
            if ($binds && $class !== null && array_filter(self::COMMANDS, fn (string $command) => $code->extends($class, $command)) !== []) {
                $surface->consumers[$class] = true;
            }
        }
    }

    /** @return list<string> */
    public static function named(Code $code, ?Expr $argument, Node $at, string $file, string $kind): array
    {
        $names = [];
        foreach ($code->templates($argument) as $name) {
            if (str_starts_with($name, Code::UNCALLED)) {
                continue;
            }
            if (str_contains($name, Code::VARYING) || str_contains($name, Code::UNCALLED)) {
                throw new \RuntimeException("{$file}:{$at->getStartLine()}: {$kind} a name that is not a constant — it is a contract, give it one");
            }
            $names[] = $name;
        }
        return $names;
    }

    // A request's path as the record names it: what varies is {}, and an address
    // with no constant part is from data, somewhere outside.
    public static function path(string $template): string
    {
        $route = explode('?', $template, 2)[0];
        if (preg_match('#^[a-z][a-z0-9+.-]*://[^/]*(/.*)?$#i', $route, $absolute)) {
            $route = $absolute[1] ?? '';
        }
        if (in_array(str_replace('/', '', $route), [Code::VARYING, ''], true)) {
            return $route === '/' ? '/' : 'external';
        }
        $segments = array_filter(explode('/', $route), fn (string $s) => $s !== '');
        return '/' . implode('/', array_map(fn (string $s) => str_contains($s, Code::VARYING) ? Code::VARYING : $s, $segments));
    }
}
