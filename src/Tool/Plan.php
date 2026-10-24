<?php

declare(strict_types=1);

namespace Specrig\Tool;

final class Plan
{
    /**
     * @param list<array{name: string, property: string, origin: string, setting: ?string, contract: ?string, failure: ?string}> $backends
     * @param list<array{kind: string, property: string, setting: string}> $resources
     * @param array<string, string> $constants
     * @param list<string> $running
     * @param list<string> $extensions
     */
    public function __construct(
        public readonly Service $service,
        public readonly string $name,
        public readonly string $framework,
        public readonly string $namespace,
        public readonly array $backends,
        public readonly array $resources,
        public readonly array $constants,
        public readonly array $running,
        public readonly string $php,
        public readonly array $extensions,
        public readonly ?string $checkout,
    ) {
    }
}
