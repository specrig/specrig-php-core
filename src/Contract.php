<?php

declare(strict_types=1);

namespace Specrig;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

final class Contract
{
    private const COMPONENTS = '#/components/schemas/';

    /** @var array<string, self> */
    private static array $loaded = [];

    private \stdClass $paths;

    private \stdClass $definitions;

    public function __construct(string $file, private readonly string $neighbour, private readonly ?string $failure = null)
    {
        $doc = is_file($file) ? Yaml::toJson((string) file_get_contents($file)) : null;
        if (!$doc instanceof \stdClass) {
            throw new \UnexpectedValueException("{$file} is not an OpenAPI document");
        }
        $this->paths = $doc->paths ?? new \stdClass();
        $this->definitions = new \stdClass();
        foreach ($doc->components->schemas ?? [] as $name => $schema) {
            $this->definitions->{$name} = self::rewritten($schema);
        }
    }

    public static function load(string $file, string $neighbour, ?string $failure): self
    {
        $key = (realpath($file) ?: $file) . "\n{$neighbour}\n{$failure}";
        return self::$loaded[$key] ??= new self($file, $neighbour, $failure);
    }

    private function template(string $verb, string $requestPath): ?string
    {
        $wanted = explode('/', $requestPath);
        $best = null;
        $most = -1;
        foreach ($this->paths as $template => $operations) {
            $segments = explode('/', (string) $template);
            if (!isset($operations->{$verb}) || count($segments) !== count($wanted)) {
                continue;
            }
            foreach ($segments as $i => $segment) {
                if (!str_starts_with($segment, '{') && $segment !== $wanted[$i]) {
                    continue 2;
                }
            }
            $literal = count(array_filter($segments, fn (string $s) => !str_starts_with($s, '{')));
            if ($literal > $most) {
                [$best, $most] = [(string) $template, $literal];
            }
        }
        return $best;
    }

    // A request is named as the route that takes it, so that it meets the neighbour's input of the same name.
    public function name(string $method, string $requestPath): string
    {
        return "{$method} " . ($this->template(strtolower($method), $requestPath) ?? $requestPath);
    }

    /** @return list<string> */
    public function request(string $method, string $requestPath, mixed $body): array
    {
        $verb = strtolower($method);
        $template = $this->template($verb, $requestPath);
        if ($template === null) {
            return ["{$method} {$requestPath}: {$this->neighbour} serves no such route"];
        }
        $schema = $this->paths->{$template}->{$verb}->requestBody->content->{'application/json'}->schema ?? null;
        if ($body === null || $schema === null) {
            return [];
        }
        return array_map(fn (string $e) => "{$method} {$requestPath} request body: {$e}", self::validate($schema, $body, $this->definitions));
    }

    /** @return list<string> */
    public function response(string $method, string $requestPath, int $status, mixed $body): array
    {
        $verb = strtolower($method);
        $template = $this->template($verb, $requestPath);
        if ($template === null) {
            return [];
        }
        $where = "{$method} {$requestPath} answered {$status}";
        $documented = $this->paths->{$template}->{$verb}->responses->{(string) $status} ?? null;
        if ($documented === null) {
            if ($status >= 500 && $this->failure !== null) {
                if (!isset($this->definitions->{$this->failure})) {
                    throw new \UnexpectedValueException("{$this->neighbour} has no schema {$this->failure}");
                }
                $schema = (object) ['$ref' => "#/definitions/{$this->failure}"];
                return array_map(fn (string $e) => "{$where}: {$e}", self::validate($schema, $body, $this->definitions));
            }
            return ["{$where}: {$this->neighbour} does not answer {$status} here"];
        }
        $content = $documented->content->{'application/json'}->schema ?? null;
        if ($content === null) {
            return [];
        }
        return array_map(fn (string $e) => "{$where}: {$e}", self::validate($content, $body, $this->definitions));
    }

    /** @return list<string> */
    private static function validate(mixed $schema, mixed $body, \stdClass $definitions): array
    {
        $root = self::rewritten($schema);
        $root = $root instanceof \stdClass ? $root : new \stdClass();
        $root->definitions = $definitions;
        $result = (new Validator())->setMaxErrors(20)->validate(Json::of($body) ?? new \stdClass(), $root);
        if ($result->isValid()) {
            return [];
        }
        $errors = [];
        foreach ((new ErrorFormatter())->format($result->error(), true) as $path => $messages) {
            foreach ($messages as $message) {
                $errors[] = "{$path} {$message}";
            }
        }
        return $errors;
    }

    private static function rewritten(mixed $schema): mixed
    {
        if ($schema instanceof \stdClass) {
            $copy = new \stdClass();
            foreach ($schema as $key => $value) {
                $copy->{$key} = $key === '$ref' && is_string($value) && str_starts_with($value, self::COMPONENTS)
                    ? '#/definitions/' . substr($value, strlen(self::COMPONENTS))
                    : self::rewritten($value);
            }
            return $copy;
        }
        if (is_array($schema)) {
            return array_map(self::rewritten(...), $schema);
        }
        return $schema;
    }
}
