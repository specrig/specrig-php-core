<?php

declare(strict_types=1);

namespace Specrig\Tool;

final class Area
{
    /**
     * @param list<string> $paths
     * @param list<string>|null $modes
     * @param list<string> $grant
     */
    public function __construct(
        public readonly string $name,
        public readonly array $paths,
        public readonly ?array $modes = null,
        public readonly array $grant = [],
    ) {
    }

    public static function of(string $name, mixed $data): self
    {
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \InvalidArgumentException("agents.areas.{$name} is not a mapping of paths, modes and grant");
        }
        if (!array_key_exists('paths', $data) || $data['paths'] === []) {
            throw new \InvalidArgumentException("agents.areas.{$name} names no paths");
        }
        return new self(
            $name,
            self::strings($name, 'paths', $data['paths'], 'paths'),
            array_key_exists('modes', $data) ? self::strings($name, 'modes', $data['modes'], 'modes') : null,
            array_key_exists('grant', $data) ? self::strings($name, 'grant', $data['grant'], 'modes') : [],
        );
    }

    /** @return list<string> */
    private static function strings(string $name, string $key, mixed $value, string $what): array
    {
        if (!is_array($value) || !array_is_list($value) || array_filter($value, fn (mixed $v) => !is_string($v)) !== []) {
            throw new \InvalidArgumentException("agents.areas.{$name}.{$key} is not a list of {$what}");
        }
        return $value;
    }
}
