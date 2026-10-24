<?php

declare(strict_types=1);

namespace Specrig\Surface;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use Specrig\Http\Inbound;
use Specrig\Surface;
use Specrig\Tool\Service;
use Symfony\Component\Yaml\Yaml;

final class Symfony
{
    private const ROUTE = ['Symfony\Component\Routing\Attribute\Route', 'Symfony\Component\Routing\Annotation\Route'];

    private const ENV = '/%env\(([^()%]+)\)%/';

    public function __construct(private readonly Service $service, private readonly Code $code)
    {
    }

    public function read(Surface $surface): void
    {
        $this->settings($surface);
        $this->routes($surface);
    }

    // %env(X)% fails the container without X; a default: processor gives it a fallback.
    private function settings(Surface $surface): void
    {
        foreach ($this->texts() as $text) {
            preg_match_all(self::ENV, $text, $found);
            foreach ($found[1] as $read) {
                self::setting($surface, $read);
            }
        }
        foreach ($this->code->nodes(fn (Node $n) => $n instanceof Node\Attribute && $n->name->getLast() === 'Autowire') as [$attribute]) {
            $env = Code::named($attribute, 'env');
            foreach ($env === null ? [] : $this->code->templates($env) as $read) {
                if (!str_contains($read, Code::VARYING)) {
                    self::setting($surface, $read);
                }
            }
        }
        foreach ($this->code->nodes(fn (Node $n) => $n instanceof Expr\FuncCall && $n->name instanceof Node\Name && $n->name->toString() === 'getenv'
            || $n instanceof Expr\ArrayDimFetch && $n->var instanceof Expr\Variable && in_array($n->var->name, ['_ENV', '_SERVER'], true)) as [$read]) {
            $key = $read instanceof Expr\FuncCall ? Code::argument($read, 0, 'name') : $read->dim;
            foreach ($this->code->templates($key) as $name) {
                if (!str_contains($name, Code::VARYING) && preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
                    $surface->settings[$name] = true;
                }
            }
        }
    }

    private static function setting(Surface $surface, string $read): void
    {
        $processors = explode(':', $read);
        $name = (string) array_pop($processors);
        if (in_array('default', $processors, true)) {
            $surface->optional[$name] = true;
        } else {
            $surface->settings[$name] = true;
        }
    }

    /** @return iterable<string> */
    private function texts(): iterable
    {
        $dirs = ["{$this->service->root}/config", ...array_map(fn (string $d) => "{$this->service->root}/{$d}", array_values($this->service->namespaces()))];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                // Symfony writes config/reference.php in dev, for an editor to complete config with.
                if (in_array($file->getExtension(), ['yaml', 'yml', 'xml', 'php'], true) && $file->getPathname() !== "{$this->service->root}/config/reference.php") {
                    yield (string) file_get_contents($file->getPathname());
                }
            }
        }
    }

    private function routes(Surface $surface): void
    {
        $imports = [];
        $files = array_filter([...glob("{$this->service->root}/config/routes/*.yaml") ?: [], "{$this->service->root}/config/routes.yaml"], 'is_file');
        foreach ($files as $file) {
            foreach (Yaml::parseFile($file) ?? [] as $name => $entry) {
                if (!is_array($entry) || str_starts_with((string) $name, 'when@')) {
                    continue;
                }
                if (isset($entry['path'])) {
                    foreach ($this->methods($entry['methods'] ?? []) as $verb) {
                        $surface->serves["{$verb} " . Inbound::route((string) (is_array($entry['path']) ? reset($entry['path']) : $entry['path']))] = true;
                    }
                } elseif (isset($entry['resource'])) {
                    $resource = is_array($entry['resource']) ? $entry['resource'] : ['path' => $entry['resource']];
                    $imports[] = [
                        realpath(dirname($file) . '/' . ($resource['path'] ?? '')) ?: null,
                        isset($resource['namespace']) ? rtrim((string) $resource['namespace'], '\\') . '\\' : null,
                        (string) ($entry['prefix'] ?? ''),
                    ];
                }
            }
        }
        foreach ($this->code->nodes(fn (Node $n) => $n instanceof Stmt\Class_ && $n->namespacedName !== null) as [$class, $file]) {
            foreach ($imports as [$path, $namespace, $prefix]) {
                $covered = $path !== null && (realpath($file) === $path || str_starts_with((string) realpath($file), "{$path}/"))
                    || $namespace !== null && str_starts_with($class->namespacedName->toString(), $namespace);
                if ($covered) {
                    $this->controller($class, $prefix, $surface);
                }
            }
        }
    }

    private function controller(Stmt\Class_ $class, string $prefix, Surface $surface): void
    {
        $own = self::attributes($class->attrGroups);
        $under = $prefix;
        $methods = [];
        $invoke = $class->getMethod('__invoke');
        if ($own !== [] && $invoke !== null && self::attributes($invoke->attrGroups) === []) {
            foreach ($own as $route) {
                $this->serve($route, $prefix, $surface);
            }
            return;
        }
        if ($own !== []) {
            $under .= '/' . ($this->code->templates(Code::argument($own[0], 0, 'path'))[0] ?? '');
            $methods = $this->methods(Code::argument($own[0], 6, 'methods'));
        }
        foreach ($class->getMethods() as $method) {
            if ($method->isPublic()) {
                foreach (self::attributes($method->attrGroups) as $route) {
                    $this->serve($route, $under, $surface, $methods);
                }
            }
        }
    }

    /** @param list<string> $inherited */
    private function serve(Node\Attribute $route, string $prefix, Surface $surface, array $inherited = []): void
    {
        $path = Code::argument($route, 0, 'path');
        $paths = $path instanceof Expr\Array_ ? array_map(fn ($item) => $item?->value, $path->items) : [$path];
        $verbs = $this->methods(Code::argument($route, 6, 'methods'));
        $verbs = $verbs === ['*'] && $inherited !== [] ? $inherited : $verbs;
        foreach ($paths as $each) {
            foreach ($this->code->templates($each) as $template) {
                foreach ($verbs as $verb) {
                    $surface->serves["{$verb} " . Inbound::route("{$prefix}/{$template}")] = true;
                }
            }
        }
    }

    /** @return list<string> */
    private function methods(mixed $methods): array
    {
        if ($methods instanceof Expr\Array_) {
            $methods = array_merge(...array_map(fn ($item) => $this->code->templates($item?->value), $methods->items));
        } elseif ($methods instanceof Node) {
            $methods = explode('|', $this->code->templates($methods)[0] ?? '');
        } elseif (is_string($methods)) {
            $methods = explode('|', $methods);
        }
        $verbs = array_values(array_filter(array_map('strtoupper', (array) $methods), fn (string $v) => $v !== '' && $v !== Code::VARYING));
        return $verbs === [] ? ['*'] : $verbs;
    }

    /**
     * @param list<Node\AttributeGroup> $groups
     * @return list<Node\Attribute>
     */
    private static function attributes(array $groups): array
    {
        $routes = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if (in_array($attribute->name->toString(), self::ROUTE, true)) {
                    $routes[] = $attribute;
                }
            }
        }
        return $routes;
    }
}
