<?php

use Tests\Support\BackgroundServer;

function benchFixturePath(): string
{
    return __DIR__ . '/fixtures/bench_server.php';
}

function benchCliPath(): string
{
    return __DIR__ . '/fixtures/bench.php';
}

/**
 * @return array{0: int, 1: resource}
 */
function startBenchServer(string $engine = 'stream_select', string $profile = 'default', int $maxConnections = 2000): array
{
    [$port, $reservedSocket] = reserveTestPort();
    socket_close($reservedSocket);

    $process = BackgroundServer::start($port, [
        'engine' => $engine,
        'fixture' => benchFixturePath(),
        'max_connections' => $maxConnections,
        'profile' => $profile,
    ]);

    return [$port, $process];
}

function runBenchMultinode(int $portA, int $portB, array $args = []): string
{
    $parts = [PHP_BINARY, benchCliPath(), 'multinode', '--port=' . $portA, '--port2=' . $portB];
    foreach ($args as $key => $value) {
        $parts[] = '--' . $key . '=' . $value;
    }

    $command = implode(' ', array_map('escapeshellarg', $parts));
    $output = shell_exec($command . ' 2>&1');

    return is_string($output) ? $output : '';
}

function benchRedisReachable(): bool
{
    if (!extension_loaded('redis')) {
        return false;
    }

    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379, 1.0);

        return $redis->ping() === true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @param resource $process
 */
function runBenchCommand(string $mode, int $port, array $args = []): string
{
    $parts = [PHP_BINARY, benchCliPath(), $mode, '--port=' . $port];
    foreach ($args as $key => $value) {
        $parts[] = '--' . $key . '=' . $value;
    }

    $command = implode(' ', array_map('escapeshellarg', $parts));
    $output = shell_exec($command . ' 2>&1');

    return is_string($output) ? $output : '';
}

/**
 * @param resource $process
 */
function stopBenchServer($process): void
{
    BackgroundServer::stop($process);
}

test('benchmark latency smoke test passes against background server', function () {
    [$port, $process] = startBenchServer();

    try {
        $output = runBenchCommand('latency', $port, ['samples' => 20]);
        expect($output)->toContain('latency:');
        expect($output)->toContain('samples=20/20');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark');

test('benchmark connections smoke test opens clients', function () {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl extension required');
    }

    [$port, $process] = startBenchServer();

    try {
        $output = runBenchCommand('connections', $port, [
            'total' => 40,
            'workers' => 4,
        ]);
        expect($output)->toContain('connections: opened=40/40');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark');

test('benchmark soak holds idle connections', function () {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl extension required');
    }

    $engine = class_exists(\Swoole\WebSocket\Server::class) ? 'swoole' : 'stream_select';
    [$port, $process] = startBenchServer($engine);

    try {
        $output = runBenchCommand('soak', $port, [
            'total' => 20,
            'workers' => 2,
            'hold' => 2,
        ]);
        expect($output)->toContain('soak: held=20/20');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark');

test('benchmark broadcast delivers to room subscribers', function () {
    [$port, $process] = startBenchServer();

    try {
        $output = runBenchCommand('broadcast', $port, [
            'clients' => 6,
            'room' => 'pest-bench',
        ]);
        expect($output)->toContain('broadcast:');
        expect($output)->toContain('delivered=5/5');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark');

test('benchmark sustained ping/pong completes round trips', function () {
    [$port, $process] = startBenchServer();

    try {
        $output = runBenchCommand('sustained', $port, ['duration' => 2]);
        expect($output)->toContain('sustained:');
        expect($output)->toMatch('/completed=\d+\/\d+/');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark');

test('benchmark swoole latency smoke test', function () {
    if (!class_exists(\Swoole\WebSocket\Server::class)) {
        $this->markTestSkipped('Swoole extension required');
    }

    [$port, $process] = startBenchServer('swoole');

    try {
        $output = runBenchCommand('latency', $port, ['samples' => 20]);
        expect($output)->toContain('samples=20/20');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark', 'swoole');

test('benchmark concurrent senders complete round trips', function () {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl extension required');
    }

    if (!class_exists(\Swoole\WebSocket\Server::class)) {
        $this->markTestSkipped('Swoole extension required');
    }

    [$port, $process] = startBenchServer('swoole');

    try {
        $output = runBenchCommand('concurrent', $port, [
            'senders' => 4,
            'duration' => 2,
        ]);
        expect($output)->toContain('concurrent:');
        expect($output)->toMatch('/completed=\d+\/\d+/');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark', 'swoole');

test('benchmark handled path validates and responds', function () {
    if (!class_exists(\Swoole\WebSocket\Server::class)) {
        $this->markTestSkipped('Swoole extension required');
    }

    [$port, $process] = startBenchServer('swoole', 'handled');

    try {
        $output = runBenchCommand('handled', $port, ['samples' => 20]);
        expect($output)->toContain('handled:');
        expect($output)->toContain('samples=20/20');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark', 'swoole');

test('benchmark soak samples health during hold window', function () {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl extension required');
    }

    if (!class_exists(\Swoole\WebSocket\Server::class)) {
        $this->markTestSkipped('Swoole extension required');
    }

    [$port, $process] = startBenchServer('swoole');

    try {
        $output = runBenchCommand('soak', $port, [
            'total' => 30,
            'workers' => 3,
            'hold' => 6,
            'sample' => 2,
        ]);
        expect($output)->toContain('soak: held=30/30');
        expect($output)->toContain('health_clients=');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark', 'swoole');

test('benchmark scaled server boots with redis registry enabled', function () {
    if (!extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis not available');
    }

    if (!class_exists(\Swoole\WebSocket\Server::class)) {
        $this->markTestSkipped('Swoole extension required');
    }

    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379, 1.0);
        expect($redis->ping())->toBeTrue();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis not reachable: ' . $e->getMessage());
    }

    [$port, $process] = startBenchServer('swoole', 'scaled');

    try {
        $output = runBenchCommand('latency', $port, ['samples' => 20]);
        expect($output)->toContain('samples=20/20');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark', 'swoole', 'redis');

test('benchmark parallel broadcast fans out with setup workers', function () {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl extension required');
    }

    if (!class_exists(\Swoole\WebSocket\Server::class)) {
        $this->markTestSkipped('Swoole extension required');
    }

    [$port, $process] = startBenchServer('swoole');

    try {
        $output = runBenchCommand('broadcast', $port, [
            'clients' => 12,
            'setup_workers' => 3,
            'room' => 'parallel-bench',
        ]);
        expect($output)->toContain('delivered=11/11');
        expect($output)->toContain('setup_workers=3');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark', 'swoole');

test('benchmark scaled multi-worker broadcast fans out across swoole workers', function () {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl extension required');
    }

    if (!extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis not available');
    }

    if (!class_exists(\Swoole\WebSocket\Server::class)) {
        $this->markTestSkipped('Swoole extension required');
    }

    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379, 1.0);
        expect($redis->ping())->toBeTrue();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis not reachable: ' . $e->getMessage());
    }

    [$port, $process] = startBenchServer('swoole', 'scaled');
    usleep(2_000_000);

    try {
        $output = runBenchCommand('broadcast', $port, [
            'clients' => 12,
            'setup_workers' => 3,
            'room' => 'scaled-bench',
        ]);
        expect($output)->toContain('delivered=11/11');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark', 'swoole', 'redis');

test('benchmark multinode redis broadcast reaches remote node', function () {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl extension required');
    }

    if (!class_exists(\Swoole\WebSocket\Server::class)) {
        $this->markTestSkipped('Swoole extension required');
    }

    if (!benchRedisReachable()) {
        $this->markTestSkipped('Redis not reachable');
    }

    [$portA, $processA] = startBenchServer('swoole', 'node-a');
    [$portB, $processB] = startBenchServer('swoole', 'node-b');
    usleep(2_000_000);

    try {
        $output = runBenchMultinode($portA, $portB, [
            'clients' => 6,
            'room' => 'pest-multinode',
        ]);

        expect($output)->toContain('delivered=5/5')
            ->and($output)->toContain('multinode:');
    } finally {
        stopBenchServer($processA);
        stopBenchServer($processB);
    }
})->group('benchmark', 'swoole', 'redis');

test('benchmark realistic db handler responds', function () {
    if (!class_exists(\Swoole\WebSocket\Server::class)) {
        $this->markTestSkipped('Swoole extension required');
    }

    if (!extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('pdo_sqlite extension required');
    }

    [$port, $process] = startBenchServer('swoole', 'realistic');

    try {
        $output = runBenchCommand('db', $port, ['samples' => 20]);
        expect($output)->toContain('db:');
        expect($output)->toContain('samples=20/20');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark', 'swoole');

test('benchmark resources endpoint reports memory metrics', function () {
    if (!class_exists(\Swoole\WebSocket\Server::class)) {
        $this->markTestSkipped('Swoole extension required');
    }

    [$port, $process] = startBenchServer('swoole');

    try {
        $output = runBenchCommand('resources', $port);
        expect($output)->toContain('resources:');
        expect($output)->toMatch('/memory=[\d.]+MB/');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark', 'swoole');

test('benchmark soak samples resources during hold', function () {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl extension required');
    }

    if (!class_exists(\Swoole\WebSocket\Server::class)) {
        $this->markTestSkipped('Swoole extension required');
    }

    [$port, $process] = startBenchServer('swoole');

    try {
        $output = runBenchCommand('soak', $port, [
            'total' => 40,
            'workers' => 4,
            'hold' => 10,
            'sample' => 2,
            'resources' => 1,
        ]);
        expect($output)->toContain('soak:');
        expect($output)->toContain('resources:');
        expect($output)->toMatch('/active_clients=\d+-\d+/');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark', 'swoole');

test('benchmark large parallel broadcast fans out', function () {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl extension required');
    }

    if (!class_exists(\Swoole\WebSocket\Server::class)) {
        $this->markTestSkipped('Swoole extension required');
    }

    [$port, $process] = startBenchServer('swoole');

    try {
        $output = runBenchCommand('broadcast', $port, [
            'clients' => 24,
            'setup_workers' => 4,
            'room' => 'large-smoke',
        ]);
        expect($output)->toContain('delivered=23/23');
        expect($output)->toContain('setup_workers=4');
    } finally {
        stopBenchServer($process);
    }
})->group('benchmark', 'swoole');
