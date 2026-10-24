<?php

declare(strict_types=1);

$log = $argv[1] ?? throw new InvalidArgumentException('usage: serve.php <log>');
$server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
if ($server === false) {
    fwrite(STDERR, "specrig proxy: {$message}\n");
    exit(1);
}
fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
fflush(STDOUT);

$refusal = "specrig: no neighbour the harness names is here\n";
while (true) {
    $client = @stream_socket_accept($server, -1);
    if ($client === false) {
        continue;
    }
    stream_set_timeout($client, 5);
    $line = trim((string) fgets($client));
    while (($header = fgets($client)) !== false && trim($header) !== '') {
    }
    if ($line !== '') {
        file_put_contents($log, $line . "\n", FILE_APPEND | LOCK_EX);
    }
    fwrite($client, "HTTP/1.1 502 Bad Gateway\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($refusal) . "\r\nConnection: close\r\n\r\n" . $refusal);
    fclose($client);
}
