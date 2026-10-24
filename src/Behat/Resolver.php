<?php

declare(strict_types=1);

namespace Specrig\Behat;

use Behat\Behat\Context\Argument\ArgumentResolver;
use Specrig\Rig;

final class Resolver implements ArgumentResolver
{
    public function __construct(private readonly Cases $cases)
    {
    }

    public function resolveArguments(\ReflectionClass $classReflection, array $arguments): array
    {
        foreach ($classReflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || array_key_exists($parameter->getName(), $arguments)
                || array_key_exists($parameter->getPosition(), $arguments)) {
                continue;
            }
            $wanted = $type->getName();
            if ($wanted === Rig::class || is_subclass_of($wanted, Rig::class)) {
                if (!is_a($this->cases->harness(), $wanted, true)) {
                    throw new \LogicException("{$classReflection->getName()} asks for a {$wanted}; tests/behat.php names {$this->cases->harness()}, which is none");
                }
                $arguments[$parameter->getName()] = $this->cases->current();
            }
        }
        return $arguments;
    }
}
