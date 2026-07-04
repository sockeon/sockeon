#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Standalone Sockeon WebSocket latency probe.
 * Copy this single file to any machine with PHP 8+ (openssl ext).
 *
 * Usage:
 *   php remote_latency.php --host=192.168.1.10 --port=19091 --samples=200 [--warmup=20]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

// ponytail: minimal WS client inlined so this file is the only dependency on remote hosts
final class SockeonLatencyClient
{
    /** @var resource|null */
    private $socket = null;

    private bool $connected = false;

    /** @var array<string, list<callable>> */
    private array $listeners = [];

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
        $this->handshake();
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
        $this->connected = false;
    }

    public function on(string $event, callable $callback): void
    {
        $this->listeners[$event][] = $callback;
    }

    /** @param array<string, mixed> $data */
    public function emit(string $event, array $data = []): void
    {
        if (!$this->connected || !is_resource($this->socket)) {
            throw new RuntimeException('not connected');
        }

        $payload = json_encode(['event' => $event, 'data' => $data], JSON_THROW_ON_ERROR);
        $written = fwrite($this->socket, $this->frame($payload));
        if ($written === false) {
            throw new RuntimeException('send failed');
        }
    }

    public function listen(int $timeout = 0): void
    {
        if (!$this->connected || !is_resource($this->socket)) {
            throw new RuntimeException('not connected');
        }

        stream_set_timeout($this->socket, $timeout);
        $read = [$this->socket];
        if (stream_select($read, $write, $except, $timeout) <= 0) {
            return;
        }

        $data = fread($this->socket, 8192);
        if ($data === false || $data === '') {
            $this->disconnect();
            return;
        }

        foreach ($this->decodeFrames($data) as $frame) {
            if ($frame['opcode'] === 8) {
                $this->disconnect();
                return;
            }
            if ($frame['opcode'] === 9) {
                fwrite($this->socket, $this->frame('', 10));
                continue;
            }
            if ($frame['opcode'] === 1 || $frame['opcode'] === 2) {
                $this->dispatch($frame['payload']);
            }
        }
    }

    private function handshake(): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('no socket');
        }

        $key = base64_encode(random_bytes(16));
        $request = "GET {$this->path} HTTP/1.1\r\n"
            . "Host: {$this->host}:{$this->port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";

        if (fwrite($this->socket, $request) === false) {
            throw new RuntimeException('handshake write failed');
        }

        $response = '';
        $deadline = time() + $this->timeout;
        while (!str_contains($response, "\r\n\r\n")) {
            if (time() > $deadline) {
                throw new RuntimeException('handshake timeout');
            }
            $chunk = fread($this->socket, 8192);
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

        $this->connected = true;
        $parts = explode("\r\n\r\n", $response, 2);
        if (($parts[1] ?? '') !== '') {
            foreach ($this->decodeFrames($parts[1]) as $frame) {
                if ($frame['opcode'] === 1 || $frame['opcode'] === 2) {
                    $this->dispatch($frame['payload']);
                }
            }
        }
    }

    private function dispatch(string $payload): void
    {
        $message = json_decode($payload, true);
        if (!is_array($message) || !is_string($message['event'] ?? null)) {
            return;
        }

        $event = $message['event'];
        $data = is_array($message['data'] ?? null) ? $message['data'] : [];
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($data);
        }
    }

    private function frame(string $payload, int $opcode = 1): string
    {
        $len = strlen($payload);
        $head = chr(0x80 | $opcode);
        if ($len <= 125) {
            $head .= chr(0x80 | $len);
        } elseif ($len <= 65535) {
            $head .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $head .= chr(0x80 | 127) . pack('J', $len);
        }

        $mask = random_bytes(4);
        $head .= $mask;
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        return $head . $masked;
    }

    /** @return list<array{opcode: int, payload: string}> */
    private function decodeFrames(string $data): array
    {
        $frames = [];
        while (strlen($data) >= 2) {
            $b1 = ord($data[0]);
            $b2 = ord($data[1]);
            $masked = ($b2 & 0x80) === 0x80;
            $len = $b2 & 0x7F;
            $off = 2;

            if ($len === 126) {
                if (strlen($data) < 4) {
                    break;
                }
                $len = unpack('n', substr($data, 2, 2))[1];
                $off = 4;
            } elseif ($len === 127) {
                if (strlen($data) < 10) {
                    break;
                }
                $len = (int) unpack('J', substr($data, 2, 8))[1];
                $off = 10;
            }

            $frameLen = $off + ($masked ? 4 : 0) + $len;
            if (strlen($data) < $frameLen) {
                break;
            }

            $payload = substr($data, $off + ($masked ? 4 : 0), $len);
            if ($masked) {
                $mask = substr($data, $off, 4);
                $unmasked = '';
                for ($i = 0; $i < $len; $i++) {
                    $unmasked .= $payload[$i] ^ $mask[$i % 4];
                }
                $payload = $unmasked;
            }

            $frames[] = ['opcode' => $b1 & 0x0F, 'payload' => $payload];
            $data = substr($data, $frameLen);
        }

        return $frames;
    }
}

/**
 * @return array{host: string, port: int, samples: int}
 */
function parseArgs(array $argv): array
{
    $options = ['host' => '127.0.0.1', 'port' => 0, 'samples' => 200, 'warmup' => 0];

    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if ($key === 'host') {
            $options['host'] = $value;
        } elseif ($key === 'port' && is_numeric($value)) {
            $options['port'] = (int) $value;
        } elseif ($key === 'samples' && is_numeric($value)) {
            $options['samples'] = max(1, (int) $value);
        } elseif ($key === 'warmup' && is_numeric($value)) {
            $options['warmup'] = max(0, (int) $value);
        }
    }

    if ($options['port'] <= 0) {
        fwrite(STDERR, "Usage: php remote_latency.php --host=HOST --port=PORT [--samples=200]\n");
        exit(1);
    }

    return $options;
}

$options = parseArgs($argv);
$host = $options['host'];
$port = $options['port'];
$samples = $options['samples'];
$warmup = min($options['warmup'], max(0, $samples - 1));
$latencies = [];

try {
    $client = new SockeonLatencyClient($host, $port);
    $client->connect();

    $lastPong = -1;
    $client->on('pong', function (array $data) use (&$lastPong): void {
        $lastPong = (int) ($data['seq'] ?? -1);
    });

    $totalRounds = $samples + $warmup;
    for ($seq = 0; $seq < $totalRounds; $seq++) {
        $lastPong = -1;
        $started = microtime(true);
        $client->emit('ping', ['seq' => $seq]);

        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $client->listen(0);
            if ($lastPong === $seq) {
                if ($seq >= $warmup) {
                    $latencies[] = (microtime(true) - $started) * 1000;
                }
                break;
            }
            usleep(500);
        }
    }

    $client->disconnect();
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($latencies === []) {
    fwrite(STDERR, "latency: no pong responses (is bench_server running with BenchEchoController?)\n");
    exit(1);
}

sort($latencies);
$count = count($latencies);
$p50 = $latencies[(int) floor(($count - 1) * 0.50)];
$p95 = $latencies[(int) floor(($count - 1) * 0.95)];
$p99 = $latencies[(int) floor(($count - 1) * 0.99)];

printf(
    "latency: host=%s port=%d samples=%d/%d warmup=%d avg=%.2fms p50=%.2fms p95=%.2fms p99=%.2fms\n",
    $host,
    $port,
    $count,
    $samples,
    $warmup,
    array_sum($latencies) / $count,
    $p50,
    $p95,
    $p99,
);
