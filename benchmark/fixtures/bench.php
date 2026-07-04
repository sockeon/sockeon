<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Sockeon\Sockeon\Connection\WebSocketClient;

/**
 * ponytail: minimal self-contained harness; upgrade path = k6/wrk + dedicated load generators.
 */

function benchUsage(): void
{
    $script = basename(__FILE__);
    fwrite(STDERR, <<<TXT
        Sockeon benchmark harness

        Usage:
          php {$script} connections [--host=127.0.0.1] [--port=PORT] [--total=200] [--workers=4]
          php {$script} throughput [--host=127.0.0.1] [--port=PORT] [--messages=5000]
          php {$script} latency    [--host=127.0.0.1] [--port=PORT] [--samples=200]
          php {$script} http       [--host=127.0.0.1] [--port=PORT] [--duration=10] [--threads=4]
          php {$script} soak       [--host=127.0.0.1] [--port=PORT] [--total=200] [--workers=4] [--hold=30] [--sample=0] [--resources=0]
          php {$script} broadcast  [--host=127.0.0.1] [--port=PORT] [--clients=50] [--room=bench]
          php {$script} sustained  [--host=127.0.0.1] [--port=PORT] [--duration=10]
          php {$script} concurrent [--host=127.0.0.1] [--port=PORT] [--senders=8] [--duration=10]
          php {$script} handled    [--host=127.0.0.1] [--port=PORT] [--samples=200]
          php {$script} db         [--host=127.0.0.1] [--port=PORT] [--samples=200]
          php {$script} resources  [--host=127.0.0.1] [--port=PORT]
          php {$script} multinode  [--host=127.0.0.1] [--port=PORT] [--port2=PORT] [--clients=20] [--room=multinode]

        Server profiles (bench server 5th arg bind, 4th profile): default, handled, scaled, node-a, node-b, realistic

        TXT);
    exit(1);
}

/**
 * @return array<string, string|int>
 */
function benchOptions(array $argv): array
{
    $options = [
        'host' => '127.0.0.1',
        'port' => 0,
        'total' => 200,
        'workers' => 4,
        'messages' => 5000,
        'samples' => 200,
        'duration' => 10,
        'threads' => 4,
        'hold' => 30,
        'sample' => 0,
        'clients' => 50,
        'room' => 'bench',
        'senders' => 8,
        'port2' => 0,
        'setup_workers' => 0,
        'resources' => 0,
    ];

    foreach (array_slice($argv, 2) as $arg) {
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }

        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (array_key_exists($key, $options)) {
            if ($key === 'room') {
                $options[$key] = $value;
            } elseif (is_numeric($value)) {
                $options[$key] = (int) $value;
            }
        }
    }

    if ($options['port'] <= 0) {
        fwrite(STDERR, "Missing --port=PORT\n");
        benchUsage();
    }

    return $options;
}

function benchJoinRoom(WebSocketClient $client, string $room): bool
{
    $joined = false;
    $client->on('room_joined', function (array $data) use (&$joined, $room): void {
        if (($data['room'] ?? '') === $room) {
            $joined = true;
        }
    });

    $client->emit('join_room', ['room' => $room, 'namespace' => '/']);

    $deadline = microtime(true) + 2.0;
    while (microtime(true) < $deadline && $client->isConnected()) {
        $client->listen(0);
        if ($joined) {
            return true;
        }
        usleep(500);
    }

    return false;
}

/**
 * @param list<WebSocketClient> $clients
 */
function benchDrainClients(array $clients, int $milliseconds = 100): void
{
    $deadline = microtime(true) + ($milliseconds / 1000);

    while (microtime(true) < $deadline) {
        foreach ($clients as $client) {
            if ($client->isConnected()) {
                $client->listen(0);
            }
        }
        usleep(1000);
    }
}

function benchHealthClientCount(string $host, int $port): ?int
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 2,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents("http://{$host}:{$port}/health", false, $context);
    if ($body === false) {
        return null;
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        return null;
    }

    $server = $payload['server'] ?? null;

    return is_array($server) && isset($server['clients']) && is_int($server['clients'])
        ? $server['clients']
        : null;
}

/**
 * @return array<string, mixed>|null
 */
function benchFetchStats(string $host, int $port): ?array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 2,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents("http://{$host}:{$port}/stats", false, $context);
    if ($body === false) {
        return null;
    }

    $payload = json_decode($body, true);

    return is_array($payload) ? $payload : null;
}

/**
 * @return array{memory: float, peak: float, active: int, cpu: float}|null
 */
function benchExtractResourceMetrics(array $stats): ?array
{
    $performance = is_array($stats['performance'] ?? null) ? $stats['performance'] : [];
    $serverMetrics = is_array($performance['server'] ?? null) ? $performance['server'] : [];
    $connections = is_array($performance['connections'] ?? null) ? $performance['connections'] : [];
    $serverInfo = is_array($stats['server_info'] ?? null) ? $stats['server_info'] : [];

    return [
        'memory' => (float) ($serverMetrics['memory_usage_mb'] ?? $serverInfo['memory_usage_mb'] ?? 0),
        'peak' => (float) ($serverMetrics['memory_peak_mb'] ?? $serverInfo['memory_peak_mb'] ?? 0),
        'active' => (int) ($serverInfo['active_clients'] ?? $connections['active_count'] ?? 0),
        'cpu' => (float) ($serverMetrics['cpu_usage_percent'] ?? 0),
    ];
}

function benchPrintResources(string $host, int $port): void
{
    $stats = benchFetchStats($host, $port);
    if ($stats === null) {
        fwrite(STDERR, "resources: /stats unavailable\n");
        return;
    }

    $metrics = benchExtractResourceMetrics($stats);
    if ($metrics === null) {
        fwrite(STDERR, "resources: /stats unavailable\n");
        return;
    }

    printf(
        "resources: memory=%.2fMB peak=%.2fMB active_clients=%d cpu=%.1f%%\n",
        $metrics['memory'],
        $metrics['peak'],
        $metrics['active'],
        $metrics['cpu'],
    );
}

/**
 * @param list<array{memory: float, peak: float, active: int, cpu: float}> $samples
 * @param list<int> $healthSamples
 */
function benchPrintResourceSummary(array $samples, array $healthSamples = []): void
{
    if ($samples === []) {
        return;
    }

    $memory = array_column($samples, 'memory');
    $peak = array_column($samples, 'peak');
    $active = array_column($samples, 'active');
    $cpu = array_column($samples, 'cpu');

    $activeMin = min($active);
    $activeMax = max($active);
    $activeNote = '';
    if ($activeMax === 0 && $healthSamples !== []) {
        $activeMin = min($healthSamples);
        $activeMax = max($healthSamples);
        $activeNote = ' via /health';
    }

    printf(
        "resources: samples=%d memory=%.2f-%.2fMB peak_max=%.2fMB active_clients=%d-%d%s cpu_avg=%.1f%%\n",
        count($samples),
        min($memory),
        max($memory),
        max($peak),
        $activeMin,
        $activeMax,
        $activeNote,
        array_sum($cpu) / count($cpu),
    );
}

function benchConnections(array $options): void
{
    $host = (string) $options['host'];
    $port = (int) $options['port'];
    $total = max(1, (int) $options['total']);
    $workers = max(1, min((int) $options['workers'], $total));

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
                $client = new WebSocketClient($host, $port);
                try {
                    $client->connect();
                    $clients[] = $client;
                    $opened++;
                } catch (Throwable) {
                    break;
                }
            }

            foreach ($clients as $client) {
                $client->disconnect();
            }

            file_put_contents(sys_get_temp_dir() . "/sockeon_bench_conn_{$worker}.json", json_encode([
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

    $elapsed = microtime(true) - $started;
    $opened = 0;
    $requested = 0;

    for ($worker = 0; $worker < $workers; $worker++) {
        $file = sys_get_temp_dir() . "/sockeon_bench_conn_{$worker}.json";
        if (!is_file($file)) {
            continue;
        }

        $payload = json_decode((string) file_get_contents($file), true);
        unlink($file);
        if (!is_array($payload)) {
            continue;
        }

        $opened += (int) ($payload['opened'] ?? 0);
        $requested += (int) ($payload['requested'] ?? 0);
    }

    printf(
        "connections: opened=%d/%d workers=%d elapsed=%.3fs rate=%.1f conn/s\n",
        $opened,
        $requested,
        $workers,
        $elapsed,
        $elapsed > 0 ? $opened / $elapsed : 0.0,
    );
}

function benchThroughput(array $options): void
{
    $host = (string) $options['host'];
    $port = (int) $options['port'];
    $messages = max(1, (int) $options['messages']);

    $client = new WebSocketClient($host, $port);
    $client->connect();

    $started = microtime(true);

    for ($i = 0; $i < $messages; $i++) {
        $client->emit('ping', ['seq' => $i]);
    }

    $elapsed = microtime(true) - $started;
    $client->disconnect();

    printf(
        "throughput: messages=%d elapsed=%.3fs rate=%.1f msg/s (client emit, no RTT wait)\n",
        $messages,
        $elapsed,
        $elapsed > 0 ? $messages / $elapsed : 0.0,
    );
}

function benchLatency(array $options): void
{
    $host = (string) $options['host'];
    $port = (int) $options['port'];
    $samples = max(1, (int) $options['samples']);
    $latencies = [];

    $client = new WebSocketClient($host, $port);
    $client->connect();

    $lastPongSeq = -1;
    $client->on('pong', function (array $data) use (&$lastPongSeq): void {
        $lastPongSeq = (int) ($data['seq'] ?? -1);
    });

    for ($seq = 0; $seq < $samples; $seq++) {
        $lastPongSeq = -1;
        $started = microtime(true);
        $client->emit('ping', ['seq' => $seq]);

        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $client->listen(0);
            if ($lastPongSeq === $seq) {
                $latencies[] = (microtime(true) - $started) * 1000;
                break;
            }
            usleep(500);
        }
    }

    $client->disconnect();

    if ($latencies === []) {
        fwrite(STDERR, "latency: no pong responses received\n");
        exit(1);
    }

    sort($latencies);
    $count = count($latencies);
    $p50 = $latencies[(int) floor(($count - 1) * 0.50)];
    $p95 = $latencies[(int) floor(($count - 1) * 0.95)];
    $p99 = $latencies[(int) floor(($count - 1) * 0.99)];
    $avg = array_sum($latencies) / $count;

    printf(
        "latency: samples=%d/%d avg=%.2fms p50=%.2fms p95=%.2fms p99=%.2fms\n",
        $count,
        $samples,
        $avg,
        $p50,
        $p95,
        $p99,
    );
}

function benchHttp(array $options): void
{
    $host = (string) $options['host'];
    $port = (int) $options['port'];
    $duration = max(1, (int) $options['duration']);
    $threads = max(1, (int) $options['threads']);
    $url = "http://{$host}:{$port}/health";

    $wrk = trim((string) shell_exec('command -v wrk'));
    if ($wrk === '') {
        fwrite(STDERR, "http: wrk not found; install wrk or hit {$url} manually\n");
        exit(1);
    }

    $command = sprintf(
        '%s -t%d -d%ds -c%d --latency %s 2>&1',
        escapeshellarg($wrk),
        $threads,
        $duration,
        min($threads * 25, 100),
        escapeshellarg($url),
    );

    passthru($command);
}

function benchSoak(array $options): void
{
    if (!function_exists('pcntl_fork')) {
        fwrite(STDERR, "soak: pcntl extension required\n");
        exit(1);
    }

    $host = (string) $options['host'];
    $port = (int) $options['port'];
    $total = max(1, (int) $options['total']);
    $workers = max(1, min((int) $options['workers'], $total));
    $hold = max(1, (int) $options['hold']);
    $sampleInterval = max(0, (int) $options['sample']);
    $stateDir = sys_get_temp_dir() . '/sockeon_bench_soak_' . getmypid();
    mkdir($stateDir);

    $perWorker = intdiv($total, $workers);
    $remainder = $total % $workers;
    $pids = [];

    for ($worker = 0; $worker < $workers; $worker++) {
        $count = $perWorker + ($worker < $remainder ? 1 : 0);
        $pid = pcntl_fork();

        if ($pid === -1) {
            fwrite(STDERR, "fork failed\n");
            exit(1);
        }

        if ($pid === 0) {
            $clients = [];
            for ($i = 0; $i < $count; $i++) {
                $client = new WebSocketClient($host, $port);
                try {
                    $client->connect();
                    $clients[] = $client;
                } catch (Throwable) {
                    break;
                }
            }

            file_put_contents("{$stateDir}/worker_{$worker}.json", json_encode(['held' => count($clients)]));
            sleep($hold);

            // ponytail: stream_select won't drain close frames promptly; exit and let kernel RST
            exit(0);
        }

        $pids[] = $pid;
    }

    $healthSamples = [];
    $resourceSamples = [];
    $soakStarted = microtime(true);
    $soakDeadline = $soakStarted + $hold;
    $trackResources = (int) $options['resources'] === 1;
    $pollInterval = $sampleInterval > 0 ? $sampleInterval : ($trackResources ? 10 : 0);

    if ($pollInterval > 0) {
        usleep(500_000);

        while (microtime(true) < $soakDeadline) {
            $remaining = $soakDeadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }

            $count = benchHealthClientCount($host, $port);
            if ($count !== null) {
                $healthSamples[] = $count;
            }

            if ($trackResources) {
                $stats = benchFetchStats($host, $port);
                if ($stats !== null) {
                    $metrics = benchExtractResourceMetrics($stats);
                    if ($metrics !== null) {
                        $resourceSamples[] = $metrics;
                    }
                }
            }

            sleep((int) min($pollInterval, max(1, $remaining)));
        }
    } else {
        sleep($hold);
        $count = benchHealthClientCount($host, $port);
        if ($count !== null) {
            $healthSamples[] = $count;
        }
    }

    $peakClients = $healthSamples === [] ? null : max($healthSamples);

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $held = 0;
    foreach (glob("{$stateDir}/worker_*.json") ?: [] as $file) {
        $payload = json_decode((string) file_get_contents($file), true);
        if (is_array($payload)) {
            $held += (int) ($payload['held'] ?? 0);
        }
        unlink($file);
    }
    rmdir($stateDir);

    $healthSummary = $peakClients === null
        ? 'n/a'
        : ($healthSamples !== []
            ? sprintf(
                '%d min=%d max=%d',
                $peakClients,
                min($healthSamples),
                max($healthSamples),
            )
            : (string) $peakClients);

    printf(
        "soak: held=%d/%d workers=%d hold=%ds health_clients=%s\n",
        $held,
        $total,
        $workers,
        $hold,
        $healthSummary,
    );

    if ($trackResources) {
        benchPrintResourceSummary($resourceSamples, $healthSamples);
    }
}

function benchBroadcastParallel(array $options): void
{
    if (!function_exists('pcntl_fork')) {
        fwrite(STDERR, "broadcast: pcntl required for setup_workers > 1\n");
        exit(1);
    }

    $host = (string) $options['host'];
    $port = (int) $options['port'];
    $clientCount = max(2, (int) $options['clients']);
    $room = (string) $options['room'];
    $setupWorkers = max(2, (int) $options['setup_workers']);
    $subscriberCount = $clientCount - 1;
    $stateDir = sys_get_temp_dir() . '/sockeon_bench_broadcast_' . getmypid();
    mkdir($stateDir);

    $perWorker = intdiv($subscriberCount, $setupWorkers);
    $remainder = $subscriberCount % $setupWorkers;
    $pids = [];

    for ($worker = 0; $worker < $setupWorkers; $worker++) {
        $count = $perWorker + ($worker < $remainder ? 1 : 0);
        if ($count === 0) {
            continue;
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite(STDERR, "fork failed\n");
            exit(1);
        }

        if ($pid === 0) {
            $clients = [];
            $received = [];

            for ($i = 0; $i < $count; $i++) {
                $index = $i;
                $client = new WebSocketClient($host, $port);
                $client->connect();
                $received[$index] = false;
                $client->on('announcement', function (array $data) use (&$received, $index): void {
                    if ((int) ($data['seq'] ?? -1) === 1) {
                        $received[$index] = true;
                    }
                });

                if (!benchJoinRoom($client, $room)) {
                    exit(1);
                }

                $clients[] = $client;
            }

            file_put_contents("{$stateDir}/ready_{$worker}.json", json_encode(['ready' => count($clients)]));

            $deadline = microtime(true) + 10.0;
            while (microtime(true) < $deadline) {
                foreach ($clients as $client) {
                    $client->listen(0);
                }

                if (!in_array(false, $received, true)) {
                    break;
                }

                usleep(100);
            }

            file_put_contents("{$stateDir}/worker_{$worker}.json", json_encode([
                'expected' => count($clients),
                'delivered' => count(array_filter($received)),
            ]));
            exit(0);
        }

        $pids[] = $pid;
    }

    $readyDeadline = microtime(true) + 30.0;
    while (microtime(true) < $readyDeadline) {
        $ready = 0;
        foreach (glob("{$stateDir}/ready_*.json") ?: [] as $file) {
            $ready++;
        }

        if ($ready >= count($pids)) {
            break;
        }

        usleep(20_000);
    }

    $publisher = new WebSocketClient($host, $port);
    $publisher->connect();
    benchJoinRoom($publisher, $room);

    $started = microtime(true);
    $publisher->emit('announce', ['seq' => 1, 'room' => $room]);
    $publisher->disconnect();

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $expected = 0;
    $delivered = 0;
    foreach (glob("{$stateDir}/worker_*.json") ?: [] as $file) {
        $payload = json_decode((string) file_get_contents($file), true);
        unlink($file);
        if (!is_array($payload)) {
            continue;
        }

        $expected += (int) ($payload['expected'] ?? 0);
        $delivered += (int) ($payload['delivered'] ?? 0);
    }

    foreach (glob("{$stateDir}/ready_*.json") ?: [] as $file) {
        unlink($file);
    }
    rmdir($stateDir);

    $elapsed = microtime(true) - $started;
    printf(
        "broadcast: room=%s clients=%d delivered=%d/%d setup_workers=%d elapsed=%.3fs\n",
        $room,
        $clientCount,
        $delivered,
        $expected,
        $setupWorkers,
        $elapsed,
    );

    if ($delivered < $expected) {
        exit(1);
    }
}

function benchBroadcast(array $options): void
{
    $setupWorkers = (int) $options['setup_workers'];
    if ($setupWorkers > 1) {
        benchBroadcastParallel($options);

        return;
    }

    benchBroadcastSequential($options);
}

function benchBroadcastSequential(array $options): void
{
    $host = (string) $options['host'];
    $port = (int) $options['port'];
    $clientCount = max(2, (int) $options['clients']);
    $room = (string) $options['room'];
    $clients = [];
    $received = array_fill(0, $clientCount, false);

    for ($i = 0; $i < $clientCount; $i++) {
        $index = $i;
        $client = new WebSocketClient($host, $port);
        $client->connect();

        if ($index > 0) {
            $client->on('announcement', function (array $data) use (&$received, $index): void {
                if ((int) ($data['seq'] ?? -1) === 1) {
                    $received[$index] = true;
                }
            });
        }

        if (!benchJoinRoom($client, $room)) {
            fwrite(STDERR, "broadcast: client {$i} failed to join room\n");
            exit(1);
        }

        $clients[] = $client;
    }

    benchDrainClients($clients);

    $started = microtime(true);
    $clients[0]->emit('announce', ['seq' => 1, 'room' => $room]);

    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        foreach (array_slice($clients, 1) as $client) {
            $client->listen(0);
        }

        if (!in_array(false, array_slice($received, 1), true)) {
            break;
        }

        usleep(100);
    }

    $elapsed = microtime(true) - $started;
    $delivered = count(array_filter(array_slice($received, 1)));

    foreach ($clients as $client) {
        $client->disconnect();
    }

    printf(
        "broadcast: room=%s clients=%d delivered=%d/%d elapsed=%.3fs\n",
        $room,
        $clientCount,
        $delivered,
        $clientCount - 1,
        $elapsed,
    );

    if ($delivered < $clientCount - 1) {
        exit(1);
    }
}

function benchSustained(array $options): void
{
    $host = (string) $options['host'];
    $port = (int) $options['port'];
    $duration = max(1, (int) $options['duration']);
    $client = new WebSocketClient($host, $port);
    $client->connect();

    $lastPongSeq = -1;
    $client->on('pong', function (array $data) use (&$lastPongSeq): void {
        $lastPongSeq = (int) ($data['seq'] ?? -1);
    });

    $completed = 0;
    $seq = 0;
    $started = microtime(true);
    $deadline = $started + $duration;

    while (microtime(true) < $deadline) {
        $lastPongSeq = -1;
        $client->emit('ping', ['seq' => $seq]);

        $responseDeadline = microtime(true) + 1.0;
        while (microtime(true) < $responseDeadline) {
            $client->listen(0);
            if ($lastPongSeq === $seq) {
                $completed++;
                break;
            }
            usleep(500);
        }

        $seq++;
    }

    $client->disconnect();
    $elapsed = microtime(true) - $started;

    printf(
        "sustained: duration=%ds completed=%d/%d rate=%.1f rtt/s avg=%.2fms\n",
        $duration,
        $completed,
        $seq,
        $elapsed > 0 ? $completed / $elapsed : 0.0,
        $completed > 0 ? ($elapsed * 1000) / $completed : 0.0,
    );

    if ($completed === 0) {
        exit(1);
    }
}

function benchConcurrent(array $options): void
{
    if (!function_exists('pcntl_fork')) {
        fwrite(STDERR, "concurrent: pcntl extension required\n");
        exit(1);
    }

    $host = (string) $options['host'];
    $port = (int) $options['port'];
    $senders = max(1, (int) $options['senders']);
    $duration = max(1, (int) $options['duration']);
    $stateDir = sys_get_temp_dir() . '/sockeon_bench_concurrent_' . getmypid();
    mkdir($stateDir);
    $started = microtime(true);
    $pids = [];

    for ($sender = 0; $sender < $senders; $sender++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite(STDERR, "fork failed\n");
            exit(1);
        }

        if ($pid === 0) {
            $client = new WebSocketClient($host, $port);
            $client->connect();

            $lastPongSeq = -1;
            $client->on('pong', function (array $data) use (&$lastPongSeq): void {
                $lastPongSeq = (int) ($data['seq'] ?? -1);
            });

            $completed = 0;
            $attempted = 0;
            $deadline = microtime(true) + $duration;

            while (microtime(true) < $deadline) {
                $seq = $attempted;
                $lastPongSeq = -1;
                $client->emit('ping', ['seq' => $seq]);

                $responseDeadline = microtime(true) + 1.0;
                while (microtime(true) < $responseDeadline) {
                    $client->listen(0);
                    if ($lastPongSeq === $seq) {
                        $completed++;
                        break;
                    }
                    usleep(500);
                }

                $attempted++;
            }

            file_put_contents("{$stateDir}/sender_{$sender}.json", json_encode([
                'completed' => $completed,
                'attempted' => $attempted,
            ]));
            exit(0);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $completed = 0;
    $attempted = 0;
    foreach (glob("{$stateDir}/sender_*.json") ?: [] as $file) {
        $payload = json_decode((string) file_get_contents($file), true);
        unlink($file);
        if (!is_array($payload)) {
            continue;
        }

        $completed += (int) ($payload['completed'] ?? 0);
        $attempted += (int) ($payload['attempted'] ?? 0);
    }
    rmdir($stateDir);

    $elapsed = microtime(true) - $started;
    printf(
        "concurrent: senders=%d duration=%ds completed=%d/%d rate=%.1f rtt/s avg=%.2fms\n",
        $senders,
        $duration,
        $completed,
        $attempted,
        $elapsed > 0 ? $completed / $elapsed : 0.0,
        $completed > 0 ? ($elapsed * 1000) / $completed : 0.0,
    );

    if ($completed === 0) {
        exit(1);
    }
}

function benchHandled(array $options): void
{
    $host = (string) $options['host'];
    $port = (int) $options['port'];
    $samples = max(1, (int) $options['samples']);
    $latencies = [];

    $client = new WebSocketClient($host, $port);
    $client->connect();

    $lastPongSeq = -1;
    $client->on('validated_pong', function (array $data) use (&$lastPongSeq): void {
        $lastPongSeq = (int) ($data['seq'] ?? -1);
    });

    for ($seq = 0; $seq < $samples; $seq++) {
        $lastPongSeq = -1;
        $started = microtime(true);
        $client->emit('validated_ping', [
            'seq' => $seq,
            'token' => 'bench-token',
        ]);

        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $client->listen(0);
            if ($lastPongSeq === $seq) {
                $latencies[] = (microtime(true) - $started) * 1000;
                break;
            }
            usleep(500);
        }
    }

    $client->disconnect();

    if ($latencies === []) {
        fwrite(STDERR, "handled: no validated_pong responses (is server profile=handled?)\n");
        exit(1);
    }

    sort($latencies);
    $count = count($latencies);
    $p50 = $latencies[(int) floor(($count - 1) * 0.50)];
    $p95 = $latencies[(int) floor(($count - 1) * 0.95)];
    $p99 = $latencies[(int) floor(($count - 1) * 0.99)];
    $avg = array_sum($latencies) / $count;

    printf(
        "handled: samples=%d/%d avg=%.2fms p50=%.2fms p95=%.2fms p99=%.2fms (validation+middleware)\n",
        $count,
        $samples,
        $avg,
        $p50,
        $p95,
        $p99,
    );
}

function benchDb(array $options): void
{
    $host = (string) $options['host'];
    $port = (int) $options['port'];
    $samples = max(1, (int) $options['samples']);
    $latencies = [];

    $client = new WebSocketClient($host, $port);
    $client->connect();

    $lastPongSeq = -1;
    $client->on('db_pong', function (array $data) use (&$lastPongSeq): void {
        $lastPongSeq = (int) ($data['seq'] ?? -1);
    });

    for ($seq = 0; $seq < $samples; $seq++) {
        $lastPongSeq = -1;
        $started = microtime(true);
        $client->emit('db_ping', ['seq' => $seq]);

        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $client->listen(0);
            if ($lastPongSeq === $seq) {
                $latencies[] = (microtime(true) - $started) * 1000;
                break;
            }
            usleep(500);
        }
    }

    $client->disconnect();

    if ($latencies === []) {
        fwrite(STDERR, "db: no db_pong responses (is server profile=realistic?)\n");
        exit(1);
    }

    sort($latencies);
    $count = count($latencies);
    $p50 = $latencies[(int) floor(($count - 1) * 0.50)];
    $p95 = $latencies[(int) floor(($count - 1) * 0.95)];
    $p99 = $latencies[(int) floor(($count - 1) * 0.99)];
    $avg = array_sum($latencies) / $count;

    printf(
        "db: samples=%d/%d avg=%.2fms p50=%.2fms p95=%.2fms p99=%.2fms (sqlite SELECT)\n",
        $count,
        $samples,
        $avg,
        $p50,
        $p95,
        $p99,
    );
}

function benchResources(array $options): void
{
    benchPrintResources((string) $options['host'], (int) $options['port']);
}

function benchMultinode(array $options): void
{
    if (!function_exists('pcntl_fork')) {
        fwrite(STDERR, "multinode: pcntl extension required\n");
        exit(1);
    }

    $host = (string) $options['host'];
    $portA = (int) $options['port'];
    $portB = (int) $options['port2'];
    $clientCount = max(4, (int) $options['clients']);
    $room = (string) $options['room'];

    if ($portB <= 0) {
        fwrite(STDERR, "multinode: missing --port2=PORT for remote node\n");
        exit(1);
    }

    $remoteSubscribers = $clientCount - 1;
    $stateDir = sys_get_temp_dir() . '/sockeon_bench_multinode_' . getmypid();
    mkdir($stateDir);

    $pid = pcntl_fork();
    if ($pid === -1) {
        fwrite(STDERR, "fork failed\n");
        exit(1);
    }

    if ($pid === 0) {
        $clients = [];
        $received = [];

        for ($i = 0; $i < $remoteSubscribers; $i++) {
            $index = $i;
            $client = new WebSocketClient($host, $portB);
            $client->connect();
            $received[$index] = false;
            $client->on('announcement', function (array $data) use (&$received, $index): void {
                if ((int) ($data['seq'] ?? -1) === 1) {
                    $received[$index] = true;
                }
            });

            if (!benchJoinRoom($client, $room)) {
                exit(1);
            }

            $clients[] = $client;
        }

        file_put_contents("{$stateDir}/remote_ready.json", json_encode(['ready' => count($clients)]));

        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            foreach ($clients as $client) {
                $client->listen(0);
            }

            if (!in_array(false, $received, true)) {
                break;
            }

            usleep(100);
        }

        file_put_contents("{$stateDir}/remote.json", json_encode([
            'expected' => count($clients),
            'delivered' => count(array_filter($received)),
        ]));
        exit(0);
    }

    $readyDeadline = microtime(true) + 30.0;
    while (microtime(true) < $readyDeadline) {
        if (is_file("{$stateDir}/remote_ready.json")) {
            break;
        }
        usleep(20_000);
    }

    usleep(300_000);
    $publisher = new WebSocketClient($host, $portA);
    $publisher->connect();
    benchJoinRoom($publisher, $room);

    $started = microtime(true);
    $publisher->emit('announce', ['seq' => 1, 'room' => $room]);
    $publisher->disconnect();

    pcntl_waitpid($pid, $status);

    $payload = json_decode((string) file_get_contents("{$stateDir}/remote.json"), true);
    unlink("{$stateDir}/remote.json");
    if (is_file("{$stateDir}/remote_ready.json")) {
        unlink("{$stateDir}/remote_ready.json");
    }
    rmdir($stateDir);

    $expected = is_array($payload) ? (int) ($payload['expected'] ?? 0) : 0;
    $delivered = is_array($payload) ? (int) ($payload['delivered'] ?? 0) : 0;
    $elapsed = microtime(true) - $started;

    printf(
        "multinode: room=%s publisher_port=%d remote_port=%d delivered=%d/%d elapsed=%.3fs\n",
        $room,
        $portA,
        $portB,
        $delivered,
        $expected,
        $elapsed,
    );

    if ($delivered < $expected) {
        exit(1);
    }
}

$mode = $argv[1] ?? '';
if ($mode === '') {
    benchUsage();
}

$options = benchOptions($argv);

match ($mode) {
    'connections' => benchConnections($options),
    'throughput' => benchThroughput($options),
    'latency' => benchLatency($options),
    'http' => benchHttp($options),
    'soak' => benchSoak($options),
    'broadcast' => benchBroadcast($options),
    'sustained' => benchSustained($options),
    'concurrent' => benchConcurrent($options),
    'handled' => benchHandled($options),
    'db' => benchDb($options),
    'resources' => benchResources($options),
    'multinode' => benchMultinode($options),
    default => benchUsage(),
};
