<?php

declare(strict_types=1);

namespace Specrig\Surface;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use Specrig\Http\Inbound;
use Specrig\Surface;
use Specrig\Tool\Service;

final class Laravel
{
    private const ROUTE = ['Illuminate\Support\Facades\Route', 'Route'];

    private const VERBS = ['get' => ['GET'], 'post' => ['POST'], 'put' => ['PUT'], 'patch' => ['PATCH'], 'delete' => ['DELETE'], 'options' => ['OPTIONS'], 'any' => ['*'], 'view' => ['GET']];

    private const RESOURCE = [
        'index' => ['GET', ''], 'create' => ['GET', '/create'], 'store' => ['POST', ''], 'show' => ['GET', '/{p}'],
        'edit' => ['GET', '/{p}/edit'], 'update' => ['PUT', '/{p}'], 'update.patch' => ['PATCH', '/{p}'], 'destroy' => ['DELETE', '/{p}'],
    ];

    private readonly NodeFinder $finder;

    /** @var array<string, true> */
    private array $walked = [];

    public function __construct(private readonly Service $service, private readonly Code $code)
    {
        $this->finder = new NodeFinder();
    }

    public function read(Surface $surface): void
    {
        $this->settings($surface);
        $this->routes($surface);
    }

    // Laravel loads every configuration file of its own as well as the service's, and
    // env() without a default falls back to null, so every read is optional.
    private function settings(Surface $surface): void
    {
        $framework = glob("{$this->service->root}/vendor/laravel/framework/config/*.php") ?: [];
        foreach ([$this->code, new Code($framework)] as $code) {
            foreach ($code->nodes(fn (Node $n) => $n instanceof Expr\FuncCall && $n->name instanceof Node\Name && strtolower($n->name->getLast()) === 'env'
                || $n instanceof Expr\StaticCall && $n->class instanceof Node\Name && in_array($n->class->toString(), ['Illuminate\Support\Env', 'Env'], true)
                    && $n->name instanceof Node\Identifier && $n->name->toString() === 'get') as [$read]) {
                foreach ($code->templates(Code::argument($read, 0, 'key')) as $key) {
                    if (!str_contains($key, Code::VARYING) && !str_contains($key, Code::UNCALLED)) {
                        $surface->optional[$key] = true;
                    }
                }
            }
        }
    }

    private function routes(Surface $surface): void
    {
        $bootstrap = "{$this->service->root}/bootstrap/app.php";
        foreach ($this->code->files[$bootstrap] ?? [] as $stmt) {
            foreach ($this->finder->find([$stmt], fn (Node $n) => $n instanceof Expr\MethodCall && $n->name instanceof Node\Identifier && $n->name->toString() === 'withRouting') as $routing) {
                $api = Code::named($routing, 'apiPrefix');
                foreach (['web' => '', 'api' => $api === null ? 'api' : $this->code->templates($api)[0]] as $name => $under) {
                    foreach ($this->paths(Code::named($routing, $name), $bootstrap) as $file) {
                        $this->file($file, $under, $surface);
                    }
                }
                $health = Code::named($routing, 'health');
                if ($health !== null) {
                    $surface->serves['GET ' . Inbound::route($this->code->templates($health)[0])] = true;
                }
            }
        }
        foreach ($this->code->files as $file => $nodes) {
            if (!str_starts_with($file, "{$this->service->root}/routes/") && $file !== $bootstrap) {
                $this->statements($nodes, '', $file, $surface, true);
            }
        }
    }

    private function file(string $file, string $prefix, Surface $surface): void
    {
        $key = "{$file}\n{$prefix}";
        if (isset($this->walked[$key]) || !isset($this->code->files[$file])) {
            return;
        }
        $this->walked[$key] = true;
        $this->statements($this->code->files[$file], $prefix, $file, $surface, false);
    }

    // Outside a routes file only a group that loads one is followed: a provider of
    // Laravel 10 loads its routes files so.
    /** @param array<Node> $nodes */
    private function statements(array $nodes, string $prefix, string $file, Surface $surface, bool $loadsOnly): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Expression) {
                $this->chain($node->expr, $prefix, $file, $surface, $loadsOnly);
            } elseif ($node instanceof Stmt\Namespace_ || $node instanceof Stmt\If_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\Return_) {
                $this->statements($loadsOnly ? $this->finder->findInstanceOf([$node], Stmt\Expression::class) : ($node->stmts ?? []), $prefix, $file, $surface, $loadsOnly);
            } elseif ($loadsOnly && ($node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_)) {
                $this->statements($this->finder->findInstanceOf([$node], Stmt\Expression::class), $prefix, $file, $surface, true);
            }
        }
    }

    private function chain(Expr $expr, string $prefix, string $file, Surface $surface, bool $loadsOnly): void
    {
        $calls = [];
        for ($at = $expr; $at instanceof Expr\MethodCall || $at instanceof Expr\StaticCall; $at = $at instanceof Expr\MethodCall ? $at->var : null) {
            array_unshift($calls, $at);
            if ($at instanceof Expr\StaticCall) {
                break;
            }
        }
        $root = $calls[0] ?? null;
        if (!$root instanceof Expr\StaticCall || !$root->class instanceof Node\Name || !in_array($root->class->toString(), self::ROUTE, true) || !$root->name instanceof Node\Identifier) {
            return;
        }
        $under = $prefix;
        $group = null;
        foreach ($calls as $call) {
            $name = $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : '';
            if ($name === 'prefix') {
                $under .= '/' . ($this->code->templates(Code::argument($call, 0, 'prefix'))[0] ?? '');
            } elseif ($name === 'group') {
                $group = $call;
            }
        }
        if ($group !== null) {
            $attributes = Code::argument($group, 0, 'attributes');
            $routes = Code::argument($group, count($group->getArgs()) > 1 ? 1 : 0, 'routes');
            if ($attributes instanceof Expr\Array_ && $routes !== $attributes) {
                foreach ($attributes->items as $item) {
                    if ($item?->key instanceof Node\Scalar\String_ && $item->key->value === 'prefix') {
                        $under .= '/' . ($this->code->templates($item->value)[0] ?? '');
                    }
                }
            }
            if ($routes instanceof Expr\Closure && !$loadsOnly) {
                $this->statements($routes->stmts, $under, $file, $surface, false);
            } elseif ($routes instanceof Expr\ArrowFunction && !$loadsOnly) {
                $this->chain($routes->expr, $under, $file, $surface, false);
            } elseif (!$routes instanceof Expr\Closure && !$routes instanceof Expr\ArrowFunction) {
                foreach ($this->paths($routes, $file) as $loaded) {
                    $this->file($loaded, $under, $surface);
                }
            }
            return;
        }
        if (!$loadsOnly) {
            $this->define($root, $calls, $prefix, $file, $surface);
        }
    }

    /** @param list<Expr\MethodCall|Expr\StaticCall> $calls */
    private function define(Expr\StaticCall $root, array $calls, string $prefix, string $file, Surface $surface): void
    {
        $name = strtolower($root->name->toString());
        if (isset(self::VERBS[$name]) || $name === 'match') {
            $verbs = $name === 'match' ? $this->verbs(Code::argument($root, 0, 'methods')) : self::VERBS[$name];
            $uri = Code::argument($root, $name === 'match' ? 1 : 0, 'uri');
            foreach (Walker::named($this->code, $uri, $root, $file, 'serves') as $route) {
                foreach ($verbs as $verb) {
                    $surface->serves["{$verb} " . Inbound::route("{$prefix}/{$route}")] = true;
                }
            }
        } elseif (in_array($name, ['resource', 'apiresource'], true)) {
            $actions = array_keys(self::RESOURCE);
            if ($name === 'apiresource') {
                $actions = array_values(array_diff($actions, ['create', 'edit']));
            }
            foreach ($calls as $call) {
                $kept = $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : '';
                if (in_array($kept, ['only', 'except'], true)) {
                    $named = $this->strings(Code::argument($call, 0, 'methods'));
                    $actions = array_values(array_filter($actions, fn (string $a) => in_array(explode('.', $a)[0], $named, true) === ($kept === 'only')));
                }
            }
            foreach (Walker::named($this->code, Code::argument($root, 0, 'name'), $root, $file, 'serves') as $resource) {
                $parameter = self::singular(basename(str_replace('.', '/', $resource)));
                foreach ($actions as $action) {
                    [$verb, $tail] = self::RESOURCE[$action];
                    $surface->serves["{$verb} " . Inbound::route("{$prefix}/{$resource}" . str_replace('{p}', "{{$parameter}}", $tail))] = true;
                }
            }
        }
    }

    /** @return list<string> */
    private function verbs(?Expr $methods): array
    {
        return array_map('strtoupper', $this->strings($methods));
    }

    /** @return list<string> */
    private function strings(?Expr $list): array
    {
        if ($list instanceof Expr\Array_) {
            $out = [];
            foreach ($list->items as $item) {
                array_push($out, ...array_filter($this->code->templates($item?->value), fn (string $s) => $s !== Code::VARYING));
            }
            return $out;
        }
        return array_values(array_filter($this->code->templates($list), fn (string $s) => $s !== Code::VARYING));
    }

    /** @return list<string> */
    private function paths(?Expr $expr, string $file): array
    {
        if ($expr instanceof Expr\Array_) {
            return array_merge(...array_map(fn ($item) => $this->paths($item?->value, $file), $expr->items));
        }
        $resolved = $this->path($expr, $file);
        return $resolved === null || !is_file($resolved) ? [] : [realpath($resolved) ?: $resolved];
    }

    private function path(?Expr $expr, string $file): ?string
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_ => str_starts_with($expr->value, '/') ? $expr->value : "{$this->service->root}/{$expr->value}",
            $expr instanceof Node\Scalar\MagicConst\Dir => dirname($file),
            $expr instanceof Expr\BinaryOp\Concat => ($left = $this->path($expr->left, $file)) !== null && $expr->right instanceof Node\Scalar\String_ ? $left . $expr->right->value : null,
            $expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name && $expr->name->getLast() === 'base_path' => $this->service->root . '/' . ltrim($this->code->templates(Code::argument($expr, 0, 'path'))[0] ?? '', '/'),
            default => null,
        };
    }

    private static function singular(string $word): string
    {
        return class_exists(\Illuminate\Support\Str::class) ? \Illuminate\Support\Str::singular($word) : preg_replace('/s$/', '', $word);
    }
}
