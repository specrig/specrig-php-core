<?php

declare(strict_types=1);

namespace Specrig;

use Psr\Clock\ClockInterface;

final class Clock implements ClockInterface
{
    private ?\DateTimeImmutable $stopped = null;

    /** @var list<\Closure(?\DateTimeImmutable): void> */
    private array $followers = [];

    public function now(): \DateTimeImmutable
    {
        return $this->stopped ?? new \DateTimeImmutable();
    }

    public function stopped(): ?\DateTimeImmutable
    {
        return $this->stopped;
    }

    public function set(\DateTimeInterface|string $at): void
    {
        $this->move(is_string($at) ? new \DateTimeImmutable($at) : \DateTimeImmutable::createFromInterface($at));
    }

    public function advance(\DateInterval|string $by): void
    {
        $from = $this->stopped ?? throw new \LogicException('the clock follows the wall clock until a step sets it');
        $this->move(is_string($by) ? $from->modify($by) : $from->add($by));
    }

    /** @param \Closure(?\DateTimeImmutable): void $follower */
    public function follow(\Closure $follower): void
    {
        $this->followers[] = $follower;
        $follower($this->stopped);
    }

    public function release(): void
    {
        $this->move(null);
        $this->followers = [];
    }

    private function move(?\DateTimeImmutable $at): void
    {
        $this->stopped = $at;
        foreach ($this->followers as $follower) {
            $follower($at);
        }
    }
}
