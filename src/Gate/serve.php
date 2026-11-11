<?php

declare(strict_types=1);

$upstream = $argv[1] ?? throw new InvalidArgumentException('usage: serve.php <host:port>');

function listener(int $port)
{
    $server = stream_socket_server("tcp://127.0.0.1:{$port}", $code, $message);
    if ($server === false) {
        fwrite(STDERR, "specrig gate: {$message}\n");
        exit(1);
    }
    return $server;
}

function forward($socket, string $data): bool
{
    while ($data !== '') {
        $sent = @stream_socket_sendto($socket, $data);
        if ($sent === false || $sent <= 0) {
            return false;
        }
        $data = substr($data, $sent);
    }
    return true;
}

$listening = listener(0);
$port = (int) substr((string) strrchr((string) stream_socket_get_name($listening, false), ':'), 1);
fwrite(STDOUT, "{$port}\n");
fflush(STDOUT);

/** @var array<int, resource> $sockets */
$sockets = [];
/** @var array<int, resource> $peers */
$peers = [];
$drop = function ($socket) use (&$sockets, &$peers): void {
    $id = get_resource_id($socket);
    foreach ([$id, isset($peers[$id]) ? get_resource_id($peers[$id]) : $id] as $gone) {
        if (isset($sockets[$gone])) {
            @fclose($sockets[$gone]);
        }
        unset($sockets[$gone], $peers[$gone]);
    }
};

while (true) {
    $read = [STDIN, ...array_values($sockets)];
    if ($listening !== null) {
        $read[] = $listening;
    }
    $write = $except = null;
    if (@stream_select($read, $write, $except, null) === false) {
        continue;
    }
    foreach ($read as $ready) {
        if ($ready === STDIN) {
            $command = fgets(STDIN);
            if ($command === false) {
                exit(0);
            }
            if (trim($command) === 'refuse') {
                if ($listening !== null) {
                    fclose($listening);
                    $listening = null;
                }
                foreach ($sockets as $socket) {
                    @fclose($socket);
                }
                $sockets = $peers = [];
            } elseif (trim($command) === 'reopen') {
                $listening ??= listener($port);
            }
            fwrite(STDOUT, "ok\n");
            fflush(STDOUT);
            continue 2;
        }
        if ($ready === $listening) {
            $client = @stream_socket_accept($listening, 0);
            $server = $client === false ? false : @stream_socket_client("tcp://{$upstream}", $code, $message, 5);
            if ($client !== false && $server === false) {
                fclose($client);
            }
            if ($client !== false && $server !== false) {
                $sockets[get_resource_id($client)] = $client;
                $sockets[get_resource_id($server)] = $server;
                $peers[get_resource_id($client)] = $server;
                $peers[get_resource_id($server)] = $client;
            }
            continue;
        }
        if (!isset($peers[get_resource_id($ready)])) {
            continue;
        }
        $data = @stream_socket_recvfrom($ready, 65536);
        if ($data === false || $data === '' || !forward($peers[get_resource_id($ready)], $data)) {
            $drop($ready);
        }
    }
}
