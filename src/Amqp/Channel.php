<?php

declare(strict_types=1);

namespace Specrig\Amqp;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

final class Channel extends AMQPChannel
{
    public function __construct(private readonly Bus $bus, private readonly Connection $owner, private readonly int $id)
    {
    }

    public function getChannelId()
    {
        return $this->id;
    }

    public function getConnection()
    {
        return $this->owner;
    }

    public function is_open()
    {
        return true;
    }

    public function close($reply_code = 0, $reply_text = '', $method_sig = [0, 0])
    {
        return null;
    }

    public function exchange_declare($exchange, $type, $passive = false, $durable = false, $auto_delete = true, $internal = false, $nowait = false, $arguments = [], $ticket = null)
    {
        $this->bus->declared($exchange, $type);
        return null;
    }

    public function exchange_delete($exchange, $if_unused = false, $nowait = false, $ticket = null)
    {
        return null;
    }

    public function queue_declare($queue = '', $passive = false, $durable = false, $exclusive = false, $auto_delete = true, $nowait = false, $arguments = [], $ticket = null)
    {
        return $this->bus->queue($queue);
    }

    public function queue_delete($queue = '', $if_unused = false, $if_empty = false, $nowait = false, $ticket = null)
    {
        return null;
    }

    public function queue_purge($queue = '', $nowait = false, $ticket = null)
    {
        return null;
    }

    public function queue_bind($queue, $exchange, $routing_key = '', $nowait = false, $arguments = [], $ticket = null)
    {
        $this->bus->bound($queue, $exchange, $routing_key);
        return null;
    }

    public function queue_unbind($queue, $exchange, $routing_key = '', $arguments = [], $ticket = null)
    {
        $this->bus->unbound($queue, $exchange, $routing_key);
        return null;
    }

    public function basic_qos($prefetch_size, $prefetch_count, $a_global)
    {
        return null;
    }

    public function basic_consume($queue = '', $consumer_tag = '', $no_local = false, $no_ack = false, $exclusive = false, $nowait = false, $callback = null, $ticket = null, $arguments = [])
    {
        return $this->bus->consume($this, $queue, $consumer_tag, (bool) $no_ack, $callback);
    }

    public function basic_cancel($consumer_tag, $nowait = false, $noreturn = false)
    {
        $this->bus->cancel($consumer_tag);
        return $consumer_tag;
    }

    // A consumer's loop ends at once with its consumer kept: the deliveries come from the steps.
    public function is_consuming()
    {
        return false;
    }

    public function wait($allowed_methods = null, $non_blocking = false, $timeout = 0)
    {
        return null;
    }

    public function consume(float $maximumPoll = 10.0): void
    {
    }

    public function stopConsume()
    {
    }

    public function basic_publish($msg, $exchange = '', $routing_key = '', $mandatory = false, $immediate = false, $ticket = null)
    {
        $headers = $msg->has('application_headers') && $msg->get('application_headers') instanceof AMQPTable
            ? $msg->get('application_headers')->getNativeData()
            : [];
        $this->bus->publish($exchange, $routing_key, $headers, $msg->getBody());
        return null;
    }

    public function batch_basic_publish($message, $exchange = '', $routing_key = '', $mandatory = false, $immediate = false, $ticket = null)
    {
        $this->basic_publish($message, $exchange, $routing_key, $mandatory, $immediate, $ticket);
    }

    public function publish_batch()
    {
    }

    public function confirm_select($nowait = false)
    {
        return null;
    }

    public function wait_for_pending_acks($timeout = 0)
    {
    }

    public function wait_for_pending_acks_returns($timeout = 0)
    {
    }

    public function set_ack_handler($callback)
    {
    }

    public function set_nack_handler($callback)
    {
    }

    public function set_return_listener($callback)
    {
    }

    public function basic_ack($delivery_tag, $multiple = false)
    {
        $this->bus->settle((int) $delivery_tag, (bool) $multiple, Settled::Acked);
    }

    public function basic_nack($delivery_tag, $multiple = false, $requeue = false)
    {
        $this->bus->settle((int) $delivery_tag, (bool) $multiple, $requeue ? Settled::Requeued : Settled::Dropped);
    }

    public function basic_reject($delivery_tag, $requeue)
    {
        $this->bus->settle((int) $delivery_tag, false, $requeue ? Settled::Requeued : Settled::Dropped);
    }

    public function __destruct()
    {
    }
}
