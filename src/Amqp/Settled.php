<?php

declare(strict_types=1);

namespace Specrig\Amqp;

enum Settled: string
{
    case Acked = 'acked';
    case Requeued = 'requeued';
    case Dropped = 'dropped';
    case Unsettled = 'unsettled';
}
