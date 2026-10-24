<?php

declare(strict_types=1);

namespace Specrig;

// A variable is read through getenv, $_ENV or $_SERVER depending on who reads it,
// so all three are set and unset together.
final class Environment
{
    public static function set(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    public static function unset(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    public static function get(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        return $value === false ? null : (string) $value;
    }
}
