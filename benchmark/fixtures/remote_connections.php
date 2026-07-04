#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Standalone Sockeon connection opener for remote load generation.
 * Copy this single file to any machine with PHP 8+ (openssl ext).
 *
 * Usage:
 *   php remote_connections.php --host=192.168.1.66 --port=19091 --total=500 --workers=8
 *   php remote_connections.php --host=192.168.1.66 --port=19091 --total=200 --workers=4 --hold=60
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

final class SockeonConnectClient
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $path = '/',
        private int $timeout = 10,
    ) {}

    public function connect(): void
    {
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            throw new RuntimeException("connect failed: {$errstr} ({$errno})");
        }

        $this->socket = $socket;
        stream_set_blocking($socket, false);
        stream_set_timeout($socket, $this->timeout);

        $key = base64_encode(random_bytes(16));
        $request = "GET {$this->path} HTTP/1.1\r\n"
            . "Host: {$this->host}:{$this->port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";

        if (fwrite($socket, $request) === false) {
            throw new RuntimeException('handshake write failed');
        }

        $response = '';
        $deadline = time() + $this->timeout;
        while (!str_contains($response, "\r\n\r\n")) {
            if (time() > $deadline) {
                throw new RuntimeException('handshake timeout');
            }
            $chunk = fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                usleep(10_000);
                continue;
            }
            $response .= $chunk;
        }

        if (!preg_match('#Sec-WebSocket-Accept:\s(.+)\r\n#i', $response, $m)) {
            throw new RuntimeException('invalid handshake response');
        }

        $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (trim($m[1]) !== $expected) {
            throw new RuntimeException('bad Sec-WebSocket-Accept');
        }
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }
}

/**
 * @return array{host: string, port: int, total: int, workers: int, hold: int}
 */
function parseArgs(array $argv): array
{
    $options = ['host' => '127.0.0.1', 'port' => 0, 'total' => 200, 'workers' => 4, 'hold' => 0];

    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if ($key === 'host') {
            $options['host'] = $value;
        } elseif ($key === 'port' && is_numeric($value)) {
            $options['port'] = (int) $value;
        } elseif ($key === 'total' && is_numeric($value)) {
            $options['total'] = max(1, (int) $value);
        } elseif ($key === 'workers' && is_numeric($value)) {
            $options['workers'] = max(1, (int) $value);
        } elseif ($key === 'hold' && is_numeric($value)) {
            $options['hold'] = max(0, (int) $value);
        }
    }

    if ($options['port'] <= 0) {
        fwrite(STDERR, "Usage: php remote_connections.php --host=HOST --port=PORT [--total=200] [--workers=4] [--hold=0]\n");
        exit(1);
    }

    return $options;
}

if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "pcntl extension required\n");
    exit(1);
}

$options = parseArgs($argv);
$host = $options['host'];
$port = $options['port'];
$total = $options['total'];
$workers = min($options['workers'], $total);
$hold = $options['hold'];
$stateDir = sys_get_temp_dir() . '/sockeon_remote_conn_' . getmypid();
mkdir($stateDir);

$perWorker = intdiv($total, $workers);
$remainder = $total % $workers;
$started = microtime(true);
$pids = [];

for ($worker = 0; $worker < $workers; $worker++) {
    $count = $perWorker + ($worker < $remainder ? 1 : 0);
    $pid = pcntl_fork();
    if ($pid === -1) {
        fwrite(STDERR, "fork failed\n");
        exit(1);
    }

    if ($pid === 0) {
        $opened = 0;
        $clients = [];

        for ($i = 0; $i < $count; $i++) {
            try {
                $client = new SockeonConnectClient($host, $port);
                $client->connect();
                $clients[] = $client;
                $opened++;
            } catch (Throwable) {
                break;
            }
        }

        if ($hold > 0) {
            sleep($hold);
        }

        foreach ($clients as $client) {
            $client->disconnect();
        }

        file_put_contents("{$stateDir}/worker_{$worker}.json", json_encode([
            'opened' => $opened,
            'requested' => $count,
        ]));
        exit(0);
    }

    $pids[] = $pid;
}

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

$opened = 0;
$requested = 0;
foreach (glob("{$stateDir}/worker_*.json") ?: [] as $file) {
    $payload = json_decode((string) file_get_contents($file), true);
    unlink($file);
    if (!is_array($payload)) {
        continue;
    }
    $opened += (int) ($payload['opened'] ?? 0);
    $requested += (int) ($payload['requested'] ?? 0);
}
rmdir($stateDir);

$elapsed = microtime(true) - $started;
printf(
    "connections: host=%s port=%d opened=%d/%d workers=%d hold=%ds elapsed=%.3fs rate=%.1f conn/s\n",
    $host,
    $port,
    $opened,
    $requested,
    $workers,
    $hold,
    $elapsed,
    $elapsed > 0 ? $opened / $elapsed : 0.0,
);

if ($opened < $requested) {
    exit(1);
}
