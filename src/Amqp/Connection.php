<?php

declare(strict_types=1);

namespace Specrig\Amqp;

use PhpAmqpLib\Connection\AMQPStreamConnection;

// A connection of the harness's own, where the container held the service's:
// it never opens a socket.
final class Connection extends AMQPStreamConnection
{
    public function __construct(private readonly Bus $bus)
    {
    }

    public function channel($channel_id = null)
    {
        return new Channel($this->bus, $this, (int) ($channel_id ?? 1));
    }

    public function isConnected()
    {
        return true;
    }

    public function isBlocked()
    {
        return false;
    }

    public function close($reply_code = 0, $reply_text = '', $method_sig = [0, 0])
    {
        return null;
    }

    public function reconnect()
    {
    }

    public function checkHeartBeat()
    {
    }

    public function __destruct()
    {
    }
}
