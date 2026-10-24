<?php

declare(strict_types=1);

namespace Specrig;

use Symfony\Component\Yaml\Yaml as Parser;

final class Checks
{
    public const FORMAT = 'checks/1';

    /**
     * @param list<string> $settings
     * @param list<string> $optional
     * @param list<string> $consumers
     */
    public function __construct(
        public readonly array $settings = [],
        public readonly array $optional = [],
        public readonly array $consumers = [],
    ) {
    }

    public static function from(string $file): ?self
    {
        if (!is_file($file)) {
            return null;
        }
        $data = Parser::parseFile($file);
        $list = fn (string $key) => array_map('strval', is_array($data) ? $data[$key] ?? [] : []);
        return new self($list('settings'), $list('optional'), $list('consumers'));
    }

    /** @return list<string> */
    public function read(): array
    {
        return array_values(array_unique([...$this->settings, ...$this->optional]));
    }

    /**
     * @param list<string> $answered
     * @return list<string>
     */
    public function unread(array $answered): array
    {
        return array_values(array_diff($answered, $this->read()));
    }
}
