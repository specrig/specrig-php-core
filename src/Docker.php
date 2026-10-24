<?php

declare(strict_types=1);

namespace Specrig;

final class Docker
{
    private readonly string $socket;

    public function __construct(?string $socket = null)
    {
        $this->socket = $socket ?? self::socket();
    }

    // Inside the suite's image the published ports are on the host, not on loopback.
    public static function host(): string
    {
        return Environment::get('TESTCONTAINERS_HOST_OVERRIDE') ?: '127.0.0.1';
    }

    /**
     * @param array<string, string> $labels
     * @return list<array<string, mixed>>
     */
    public function containers(array $labels): array
    {
        $filters = json_encode(['label' => array_map(fn ($k, $v) => "{$k}={$v}", array_keys($labels), $labels)]);
        return $this->call('GET', '/containers/json?all=1&filters=' . rawurlencode((string) $filters));
    }

    /**
     * @param list<string> $env
     * @param array<string, string> $labels
     * @param list<string> $command
     */
    public function run(string $image, int $port, array $env, array $labels, ?string $name = null, array $command = []): string
    {
        $spec = [
            'Image' => $image,
            'Env' => $env,
            'Labels' => (object) $labels,
            'ExposedPorts' => (object) ["{$port}/tcp" => new \stdClass()],
            'HostConfig' => ['PortBindings' => ["{$port}/tcp" => [['HostPort' => '']]]],
        ] + ($command === [] ? [] : ['Cmd' => $command]);
        $path = '/containers/create' . ($name === null ? '' : '?name=' . rawurlencode($name));
        try {
            $created = $this->call('POST', $path, $spec);
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
            $this->pull($image);
            $created = $this->call('POST', $path, $spec);
        }
        $this->start($created['Id']);
        return $created['Id'];
    }

    public function start(string $id): void
    {
        $this->call('POST', "/containers/{$id}/start");
    }

    public function remove(string $id): void
    {
        $this->call('DELETE', "/containers/{$id}?force=1&v=1");
    }

    public function port(string $id, int $port): int
    {
        for ($tries = 0; $tries < 50; $tries++) {
            $bound = $this->call('GET', "/containers/{$id}/json")['NetworkSettings']['Ports']["{$port}/tcp"][0]['HostPort'] ?? null;
            if ($bound !== null && $bound !== '') {
                return (int) $bound;
            }
            usleep(100_000);
        }
        throw new \RuntimeException("container {$id} publishes no port for {$port}");
    }

    private function pull(string $image): void
    {
        preg_match('#^(.+?)(?::([^:/]+))?$#', $image, $named);
        $pulled = $this->call('POST', '/images/create?fromImage=' . rawurlencode($named[1]) . '&tag=' . rawurlencode($named[2] ?? 'latest'));
        if (isset($pulled['error'])) {
            throw new \RuntimeException("docker: {$image} does not pull: {$pulled['error']}");
        }
    }

    /** @return array<mixed> */
    private function call(string $method, string $path, mixed $body = null): array
    {
        $curl = curl_init("http://docker{$path}");
        curl_setopt_array($curl, [
            CURLOPT_UNIX_SOCKET_PATH => $this->socket,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_TIMEOUT => 600,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ] + ($body === null ? [] : [CURLOPT_POSTFIELDS => json_encode($body)]));
        $answer = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($answer === false) {
            throw new \RuntimeException("docker at {$this->socket}: " . curl_error($curl));
        }
        if ($status >= 400) {
            $message = json_decode((string) $answer, true)['message'] ?? $answer;
            throw new \RuntimeException("docker: {$method} {$path}: {$message}", $status);
        }
        $last = (string) $answer;
        if (str_contains($last, "\n") && str_starts_with($path, '/images/')) {
            $last = (string) array_slice(array_filter(explode("\n", $last)), -1)[0];
        }
        $data = json_decode($last, true);
        return is_array($data) ? $data : [];
    }

    private static function socket(): string
    {
        $host = Environment::get('DOCKER_HOST');
        if ($host !== null && str_starts_with($host, 'unix://')) {
            return substr($host, strlen('unix://'));
        }
        $home = $_SERVER['HOME'] ?? '';
        foreach (['/var/run/docker.sock', "{$home}/.docker/run/docker.sock", "{$home}/.orbstack/run/docker.sock", "{$home}/.colima/default/docker.sock", "{$home}/.rd/docker.sock"] as $socket) {
            if (file_exists($socket)) {
                return $socket;
            }
        }
        return '/var/run/docker.sock';
    }
}
