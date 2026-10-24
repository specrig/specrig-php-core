<?php

declare(strict_types=1);

namespace Specrig;

final class Trace
{
    /** @var list<Record> */
    private array $records = [];

    private int $seq = 0;

    private string $step = '';

    /** @return list<Record> */
    public function records(): array
    {
        return $this->records;
    }

    // Context, Action or Outcome: what a Given, a When and a Then are for.
    public function act(string $kind): void
    {
        $this->step = $kind;
    }

    /** @return array{int, string} */
    public function begin(): array
    {
        return [++$this->seq, $this->step];
    }

    /** @param array{int, string} $call */
    public function end(array $call, string $dir, string $kind, string $name, mixed $request = null, ?string $error = null): void
    {
        $this->records[] = new Record($call[0], $dir, $kind, $name, $call[1], $request, $error);
    }
}
