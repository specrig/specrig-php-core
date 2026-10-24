<?php

declare(strict_types=1);

namespace Specrig;

use Specrig\Http\Client;

abstract class Rig
{
    /** @var list<\Closure(): void> */
    private static array $ending = [];

    public readonly Trace $trace;

    public readonly Clock $clock;

    /** @var array<string, StandIn> */
    private array $neighbours = [];

    /** @var array<string, Resource> */
    private array $resources = [];

    private readonly StandIns $standIns;

    private readonly Proxy $proxy;

    /** @var array<class-string, object> */
    private array $replaced = [];

    /** @var array<string, string> */
    private array $addresses = [];

    private bool $booted = false;

    public function __construct()
    {
        $this->trace = new Trace();
        $this->clock = new Clock();
        foreach ((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $value = $property->isInitialized($this) ? $property->getValue($this) : null;
            if ($value instanceof StandIn) {
                $this->neighbours[$property->getName()] = $value;
            } elseif ($value instanceof Resource) {
                $this->resources[$property->getName()] = $value;
            }
        }
        foreach ($this->neighbours as $name => $neighbour) {
            $neighbour->attach($name, $this->trace, static::root());
        }
        $this->standIns = new StandIns(array_values($this->neighbours));
        foreach ($this->resources as $resource) {
            $resource->attach($this->trace, fn () => $this->boot());
        }
        $this->proxy = Proxy::start();
        $this->proxy->mark();
    }

    /** @param \Closure(): void $down */
    public static function ending(\Closure $down): void
    {
        self::$ending[] = $down;
    }

    public static function endRun(): void
    {
        $downs = array_reverse(self::$ending);
        self::$ending = [];
        $failures = [];
        foreach ($downs as $down) {
            try {
                $down();
            } catch (\Throwable $e) {
                $failures[] = $e->getMessage();
            }
        }
        if ($failures !== []) {
            throw new \RuntimeException("the run's resources did not all come down: " . implode('; ', $failures));
        }
    }

    abstract public function service(): string;

    /** @return array<string, string|null> */
    protected function constants(): array
    {
        return [];
    }

    /** @return list<class-string> */
    protected function allow(): array
    {
        return [];
    }

    /** @return list<class-string> */
    protected function running(): array
    {
        return [];
    }

    abstract public function get(string $id): mixed;

    abstract public function http(): Client;

    abstract public function run(string|object $job): void;

    abstract public function command(string $name, string ...$args): int;

    /** @return list<string> */
    abstract public function work(): array;

    abstract public function databases(): Databases;

    /** @return array<string, string|null> */
    abstract protected function framework(): array;

    abstract protected function start(): void;

    abstract protected function keepRunning(string $command): void;

    abstract protected function stop(): void;

    /** @return list<string> */
    protected function shipped(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function more(): array
    {
        return [];
    }

    public static function tests(): string
    {
        return dirname((string) (new \ReflectionClass(static::class))->getFileName(), 2);
    }

    public static function root(): string
    {
        return dirname(static::tests());
    }

    public function standIns(): StandIns
    {
        return $this->standIns;
    }

    public function replace(string $type, object $instance): void
    {
        if ($this->booted) {
            throw new \LogicException("{$this->service()} has booted: replace {$type} in a Given, before a step reaches the service");
        }
        if (!in_array($type, $this->allow(), true)) {
            throw new \LogicException("{$type} is not granted: allow() in {$this->named()} does not name it");
        }
        $this->replaced[$type] = $instance;
    }

    /** @return array<class-string, object> */
    protected function replaced(): array
    {
        return $this->replaced;
    }

    /**
     * @template T of Resource
     * @param class-string<T> $kind
     * @return list<T>
     */
    protected function resources(string $kind): array
    {
        return array_values(array_filter($this->resources, fn (Resource $resource) => $resource instanceof $kind));
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        foreach ($this->resources as $resource) {
            $this->addresses[$resource->setting()] = $resource->provision($this->service());
        }
        $answers = $this->answers();
        $checks = Checks::from(static::tests() . '/checks.yaml');
        foreach (array_diff($checks?->read() ?? [], array_keys($answers)) as $unanswered) {
            Environment::unset($unanswered);
        }
        foreach ([...$answers, ...$this->framework(), ...$this->proxy->environment()] as $name => $value) {
            $value === null ? Environment::unset($name) : Environment::set($name, $value);
        }
        if ($checks !== null) {
            $this->check($checks, array_keys($answers));
        }
        $this->start();
        $this->booted = true;
        foreach ($this->resources as $resource) {
            $resource->ready($this);
        }
        foreach ($this->running() as $command) {
            $this->keepRunning(ltrim($command, '\\'));
        }
    }

    public function booted(): bool
    {
        return $this->booted;
    }

    /** @return list<string> */
    public function failures(): array
    {
        $outside = [
            ...$this->standIns->outside(),
            ...array_map(fn (string $request) => "{$request}, past the stand-ins", array_values(array_unique($this->proxy->refused()))),
        ];
        $failures = $outside === [] ? [] : ["requests to an origin {$this->named()} does not name: " . implode(', ', $outside)];
        foreach ($this->neighbours as $neighbour) {
            array_push($failures, ...$neighbour->failures());
        }
        foreach ($this->resources as $resource) {
            array_push($failures, ...$resource->failures());
        }
        return [...$failures, ...$this->more()];
    }

    public function dispose(): void
    {
        try {
            if ($this->booted) {
                $this->booted = false;
                $this->stop();
            }
        } finally {
            foreach ($this->resources as $resource) {
                $resource->release();
            }
            $this->clock->release();
        }
    }

    /**
     * @template T
     * @param \Closure(): T $call
     * @return T
     */
    protected function traced(string $kind, string $name, mixed $request, \Closure $call): mixed
    {
        $this->boot();
        $began = $this->trace->begin();
        $error = null;
        try {
            $result = $call();
            if (is_int($result) && $result !== 0) {
                $error = "exit {$result}";
            }
            return $result;
        } catch (\Throwable $e) {
            $error = (new \ReflectionClass($e))->getShortName();
            throw $e;
        } finally {
            $this->trace->end($began, 'in', $kind, $name, $request, $error);
        }
    }

    protected function named(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    /** @return array<string, string|null> */
    private function answers(): array
    {
        $answers = [];
        foreach ($this->neighbours as $neighbour) {
            if ($neighbour->setting() !== null) {
                $answers[$neighbour->setting()] = $neighbour->origin();
            }
        }
        return [...$answers, ...$this->addresses, ...$this->constants()];
    }

    /** @param list<string> $answered */
    private function check(Checks $checks, array $answered): void
    {
        $wrong = [];
        $unanswered = array_values(array_diff($checks->settings, $answered, $this->shipped()));
        if ($unanswered !== []) {
            $wrong[] = "{$this->service()} reads " . implode(', ', $unanswered) . ", which neither what it ships nor {$this->named()} answers: answer it in constants()";
        }
        $unread = $checks->unread($answered);
        if ($unread !== []) {
            $wrong[] = "{$this->named()} answers " . implode(', ', $unread) . ", which {$this->service()} does not read";
        }
        $running = array_map(fn (string $type) => ltrim($type, '\\'), $this->running());
        $idle = array_values(array_diff($checks->consumers, $running));
        if ($idle !== []) {
            $wrong[] = implode(', ', $idle) . ' binds a queue and running() does not name it: its events would reach nothing';
        }
        if ($wrong !== []) {
            throw new \RuntimeException(implode("\n", $wrong));
        }
    }
}
