<?php

declare(strict_types=1);

namespace Specrig\Surface;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

final class Code
{
    public const DEEPEST = 12;

    public const VARYING = '{}';

    // A parameter of a method nobody calls carries no value at all.
    public const UNCALLED = "\0";

    /** @var array<string, list<Node>> */
    public array $files = [];

    /** @var array<string, Stmt\ClassLike> */
    private array $classes = [];

    /** @var array<string, list<array{Node, string}>> */
    private array $calls = [];

    private readonly NodeFinder $finder;

    /** @param list<string> $files */
    public function __construct(array $files)
    {
        $this->finder = new NodeFinder();
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        foreach ($files as $file) {
            try {
                $nodes = $parser->parse((string) file_get_contents($file)) ?? [];
            } catch (\PhpParser\Error $e) {
                throw new \RuntimeException("{$file}: {$e->getMessage()}", 0, $e);
            }
            $traverser = new NodeTraverser(new NameResolver(), new ParentConnectingVisitor());
            $this->files[$file] = $traverser->traverse($nodes);
            foreach ($this->finder->findInstanceOf($this->files[$file], Stmt\ClassLike::class) as $class) {
                if ($class->namespacedName !== null) {
                    $this->classes[$class->namespacedName->toString()] = $class;
                }
            }
            foreach ($this->finder->find($this->files[$file], fn (Node $n) => $n instanceof Expr\MethodCall || $n instanceof Expr\StaticCall || $n instanceof Expr\NullsafeMethodCall) as $call) {
                if ($call->name instanceof Node\Identifier) {
                    $this->calls[strtolower($call->name->toString())][] = [$call, $file];
                }
            }
        }
    }

    /** @return iterable<array{Node, string}> */
    public function nodes(callable $filter): iterable
    {
        foreach ($this->files as $file => $nodes) {
            foreach ($this->finder->find($nodes, $filter) as $node) {
                yield [$node, $file];
            }
        }
    }

    public function class(string $name): ?Stmt\ClassLike
    {
        return $this->classes[ltrim($name, '\\')] ?? null;
    }

    /** @return list<string> */
    public function ancestry(string $name): array
    {
        $line = [];
        for ($at = ltrim($name, '\\'); $at !== '' && !in_array($at, $line, true) && count($line) < self::DEEPEST;) {
            $line[] = $at;
            $class = $this->class($at);
            $at = $class instanceof Stmt\Class_ && $class->extends !== null ? $class->extends->toString() : '';
        }
        return $line;
    }

    public function extends(string $name, string $ancestor): bool
    {
        if (in_array(ltrim($ancestor, '\\'), $this->ancestry($name), true)) {
            return true;
        }
        $last = $this->ancestry($name);
        $top = end($last);
        return $top !== false && $this->class($top) === null && is_a($top, $ancestor, true);
    }

    public static function enclosing(Node $node, string $kind): ?Node
    {
        for ($at = $node->getAttribute('parent'); $at !== null; $at = $at->getAttribute('parent')) {
            if ($at instanceof $kind) {
                return $at;
            }
        }
        return null;
    }

    public static function function(Node $node): ?Node\FunctionLike
    {
        for ($at = $node->getAttribute('parent'); $at !== null; $at = $at->getAttribute('parent')) {
            if ($at instanceof Node\FunctionLike) {
                return $at;
            }
        }
        return null;
    }

    public static function className(Node $node): ?string
    {
        $class = self::enclosing($node, Stmt\ClassLike::class);
        return $class?->namespacedName?->toString();
    }

    public static function named(Node $call, string $name): ?Expr
    {
        foreach (self::args($call) as $arg) {
            if ($arg->name?->toString() === $name) {
                return $arg->value;
            }
        }
        return null;
    }

    public static function argument(Node $call, int $position, string $name): ?Expr
    {
        $args = self::args($call);
        foreach ($args as $arg) {
            if ($arg->name?->toString() === $name) {
                return $arg->value;
            }
        }
        $positional = array_values(array_filter($args, fn (Node\Arg $a) => $a->name === null));
        return isset($positional[$position]) && !$positional[$position]->unpack ? $positional[$position]->value : null;
    }

    /** @return list<Node\Arg> */
    private static function args(Node $call): array
    {
        return match (true) {
            $call instanceof Node\Attribute => $call->args,
            $call instanceof Expr\CallLike && !$call->isFirstClassCallable() => $call->getArgs(),
            default => [],
        };
    }

    /** @return list<string> */
    public function templates(?Node $expr, int $depth = 0): array
    {
        if ($expr === null || $depth > self::DEEPEST) {
            return [self::VARYING];
        }
        return match (true) {
            $expr instanceof Node\Scalar\String_ => [$expr->value],
            $expr instanceof Node\Scalar\Int_, $expr instanceof Node\Scalar\Float_ => [(string) $expr->value],
            $expr instanceof Node\Scalar\InterpolatedString => array_reduce(
                $expr->parts,
                fn (array $sofar, Node $part) => self::product($sofar, $part instanceof Node\InterpolatedStringPart ? [$part->value] : $this->piece($part, $depth + 1)),
                [''],
            ),
            $expr instanceof Expr\BinaryOp\Concat => self::product($this->piece($expr->left, $depth + 1), $this->piece($expr->right, $depth + 1)),
            $expr instanceof Expr\Ternary => [...$this->templates($expr->if ?? $expr->cond, $depth + 1), ...$this->templates($expr->else, $depth + 1)],
            $expr instanceof Expr\BinaryOp\Coalesce => [...$this->templates($expr->left, $depth + 1), ...$this->templates($expr->right, $depth + 1)],
            $expr instanceof Expr\ClassConstFetch => $this->constant($expr, $depth),
            $expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name && in_array(strtolower($expr->name->toString()), ['sprintf', 'vsprintf'], true) => $this->formatted($expr, $depth),
            $expr instanceof Expr\Variable && is_string($expr->name) => $this->variable($expr, $depth),
            $expr instanceof Expr\PropertyFetch && $expr->var instanceof Expr\Variable && $expr->var->name === 'this' && $expr->name instanceof Node\Identifier => $this->property($expr, $depth),
            default => [self::VARYING],
        };
    }

    /** @return list<string> */
    private function piece(Node $expr, int $depth): array
    {
        $pieces = $this->templates($expr, $depth);
        return $pieces === [] ? [self::VARYING] : $pieces;
    }

    /** @return list<string> */
    private function constant(Expr\ClassConstFetch $fetch, int $depth): array
    {
        if (!$fetch->name instanceof Node\Identifier || !$fetch->class instanceof Node\Name) {
            return [self::VARYING];
        }
        $class = $fetch->class->toString();
        if (in_array(strtolower($class), ['self', 'static'], true)) {
            $class = self::className($fetch) ?? '';
        }
        if ($fetch->name->toString() === 'class') {
            return [ltrim($class, '\\')];
        }
        foreach ($this->ancestry($class) as $at) {
            foreach ($this->class($at)?->getConstants() ?? [] as $constants) {
                foreach ($constants->consts as $const) {
                    if ($const->name->toString() === $fetch->name->toString()) {
                        return $this->templates($const->value, $depth + 1);
                    }
                }
            }
        }
        return [self::VARYING];
    }

    /** @return list<string> */
    private function formatted(Expr\FuncCall $call, int $depth): array
    {
        $format = self::argument($call, 0, 'format');
        if (!$format instanceof Node\Scalar\String_) {
            return [self::VARYING];
        }
        return [preg_replace_callback('/%%|%[-+ 0#\']*\d*(?:\.\d+)?[a-zA-Z]/', fn (array $m) => $m[0] === '%%' ? '%' : self::VARYING, $format->value)];
    }

    /** @return list<string> */
    private function variable(Expr\Variable $variable, int $depth): array
    {
        $function = self::function($variable);
        if ($function === null) {
            return [self::VARYING];
        }
        foreach ($function->getParams() as $position => $param) {
            if ($param->var instanceof Expr\Variable && $param->var->name === $variable->name) {
                return $this->parameter($function, $position, (string) $variable->name, $depth);
            }
        }
        $found = [];
        foreach ($this->finder->find($function->getStmts() ?? [], fn (Node $n) => $n instanceof Expr\Assign && $n->var instanceof Expr\Variable && $n->var->name === $variable->name) as $assign) {
            array_push($found, ...$this->templates($assign->expr, $depth + 1));
        }
        return $found === [] ? [self::VARYING] : array_values(array_unique($found));
    }

    /** @return list<string> */
    private function parameter(Node\FunctionLike $function, int $position, string $name, int $depth): array
    {
        if (!$function instanceof Stmt\ClassMethod) {
            return [self::VARYING];
        }
        $callers = $this->callers($function);
        if ($callers === []) {
            return [self::UNCALLED];
        }
        $found = [];
        foreach ($callers as $call) {
            array_push($found, ...$this->templates(self::argument($call, $position, $name), $depth + 1));
        }
        return array_values(array_unique($found));
    }

    /** @return list<Expr\CallLike> */
    public function callers(Stmt\ClassMethod $method): array
    {
        $owner = self::className($method);
        $callers = [];
        foreach ($this->calls[strtolower($method->name->toString())] ?? [] as [$call]) {
            $receiver = $call instanceof Expr\StaticCall ? $call->class : $call->var;
            $types = $receiver instanceof Node\Name
                ? [in_array(strtolower($receiver->toString()), ['self', 'static', 'parent'], true) ? (string) self::className($call) : $receiver->toString()]
                : $this->types($receiver);
            foreach ($types as $type) {
                if ($owner !== null && ($type === $owner || $this->extends($type, $owner))) {
                    $callers[] = $call;
                    break;
                }
            }
        }
        return $callers;
    }

    /** @return list<string> */
    private function property(Expr\PropertyFetch $fetch, int $depth): array
    {
        $class = self::className($fetch);
        $name = $fetch->name->toString();
        $found = [];
        foreach ($this->ancestry((string) $class) as $at) {
            foreach ($this->class($at)?->getProperties() ?? [] as $property) {
                foreach ($property->props as $prop) {
                    if ($prop->name->toString() === $name && $prop->default !== null) {
                        array_push($found, ...$this->templates($prop->default, $depth + 1));
                    }
                }
            }
        }
        return $found === [] ? [self::VARYING] : $found;
    }

    /** @return list<string> */
    public function types(?Node $expr, int $depth = 0): array
    {
        if ($expr === null || $depth > self::DEEPEST) {
            return [];
        }
        if ($expr instanceof Expr\Variable && $expr->name === 'this') {
            return array_filter([(string) self::className($expr)]);
        }
        if ($expr instanceof Expr\New_ && $expr->class instanceof Node\Name) {
            return [$expr->class->toString()];
        }
        if (($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch) && $expr->name instanceof Node\Identifier) {
            $types = [];
            foreach ($this->types($expr->var, $depth + 1) as $owner) {
                array_push($types, ...$this->propertyTypes($owner, $expr->name->toString()));
            }
            return $types;
        }
        if (($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall) && $expr->name instanceof Node\Identifier) {
            $types = [];
            foreach ($this->types($expr->var, $depth + 1) as $owner) {
                array_push($types, ...$this->returns($owner, $expr->name->toString(), $depth + 1));
            }
            return $types;
        }
        if ($expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier) {
            $class = $expr->class->toString();
            return Clients::facade($class) ?? $this->returns(in_array(strtolower($class), ['self', 'static'], true) ? (string) self::className($expr) : $class, $expr->name->toString(), $depth + 1);
        }
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            $function = self::function($expr);
            foreach ($function?->getParams() ?? [] as $param) {
                if ($param->var instanceof Expr\Variable && $param->var->name === $expr->name) {
                    return self::declared($param->type);
                }
            }
            $types = [];
            foreach ($this->finder->find($function?->getStmts() ?? [], fn (Node $n) => $n instanceof Expr\Assign && $n->var instanceof Expr\Variable && $n->var->name === $expr->name) as $assign) {
                array_push($types, ...$this->types($assign->expr, $depth + 1));
            }
            return $types;
        }
        return [];
    }

    /** @return list<string> */
    private function propertyTypes(string $class, string $name): array
    {
        foreach ($this->ancestry($class) as $at) {
            $declared = $this->class($at);
            foreach ($declared?->getProperties() ?? [] as $property) {
                foreach ($property->props as $prop) {
                    if ($prop->name->toString() === $name) {
                        return self::declared($property->type);
                    }
                }
            }
            foreach ($declared?->getMethod('__construct')?->getParams() ?? [] as $param) {
                if ($param->flags !== 0 && $param->var instanceof Expr\Variable && $param->var->name === $name) {
                    return self::declared($param->type);
                }
            }
        }
        return [];
    }

    /** @return list<string> */
    private function returns(string $class, string $method, int $depth): array
    {
        if (Clients::fluent($class, $method)) {
            return [$class];
        }
        foreach ($this->ancestry($class) as $at) {
            $declared = $this->class($at)?->getMethod($method);
            if ($declared === null) {
                continue;
            }
            $named = self::declared($declared->returnType);
            if ($named !== [] && !in_array(strtolower($named[0]), ['static', 'self'], true)) {
                return $named;
            }
            $types = [];
            foreach ($this->finder->findInstanceOf($declared->getStmts() ?? [], Stmt\Return_::class) as $return) {
                array_push($types, ...$this->types($return->expr, $depth + 1));
            }
            return $types;
        }
        return [];
    }

    /** @return list<string> */
    private static function declared(?Node $type): array
    {
        return match (true) {
            $type instanceof Node\Name => [$type->toString()],
            $type instanceof Node\NullableType => self::declared($type->type),
            $type instanceof Node\UnionType, $type instanceof Node\IntersectionType => array_merge(...array_map(self::declared(...), $type->types)),
            default => [],
        };
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     * @return list<string>
     */
    private static function product(array $left, array $right): array
    {
        $out = [];
        foreach ($left as $l) {
            foreach ($right as $r) {
                $out[] = $l . $r;
            }
        }
        return $out;
    }
}
