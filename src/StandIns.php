<?php

declare(strict_types=1);

namespace Specrig;

use Specrig\Http\Request;
use Specrig\Http\Response;
use Specrig\Http\Unreachable;

final class StandIns
{
    /** @var list<string> */
    private array $outside = [];

    /** @param list<StandIn> $standIns */
    public function __construct(private readonly array $standIns)
    {
    }

    /** @return list<string> */
    public function outside(): array
    {
        return $this->outside;
    }

    public function went(string $request): void
    {
        $this->outside[] = $request;
    }

    public function send(Request $request): Response
    {
        $origin = $request->origin();
        foreach ($this->standIns as $standIn) {
            if ($standIn->origin() === $origin) {
                return $standIn->answer($request);
            }
        }
        $this->outside[] = strtoupper($request->method) . " {$request->url}";
        throw new Unreachable("{$origin} is no neighbour the harness names");
    }
}
