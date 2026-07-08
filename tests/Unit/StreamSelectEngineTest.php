<?php

use Sockeon\Sockeon\Config\ServerConfig;
use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Engine\EngineFactory;
use Sockeon\Sockeon\Engine\StreamSelectEngine;
use Sockeon\Sockeon\Logging\Logger;
use Tests\Support\BackgroundServer;

test('stream select engine exposes transport metadata', function () {
    $engine = new StreamSelectEngine();

    expect($engine->getName())->toBe('stream_select')
        ->and($engine->framesOutboundWebSocket())->toBeTrue();
});

test('stream select engine is created by factory by default', function () {
    expect(EngineFactory::create(new ServerConfig()))->toBeInstanceOf(StreamSelectEngine::class);
});

test('stream select engine rejects connections when max_connections is reached', function () {
    $serverConfig = new ServerConfig([
        'survivability' => ['max_connections' => 1],
    ]);

    $logger = new Logger();
    $logger->setLogToConsole(false);
    $serverConfig->setLogger($logger);

    $server = new Server($serverConfig);
    $server->bootstrapEngineRuntime();
    $engine = new StreamSelectEngine();
    $engine->setServer($server);

    $firstPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $secondPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    expect($firstPair)->not->toBeFalse()
        ->and($secondPair)->not->toBeFalse();

    [$firstClient, $firstPeer] = $firstPair;
    [$secondClient, $secondPeer] = $secondPair;

    $server->acceptClientConnection($firstPeer);
    $server->acceptClientConnection($secondPeer);

    expect($server->getClientCount())->toBe(1)
        ->and(@feof($secondClient))->toBeTrue();

    if (is_resource($firstClient)) {
        fclose($firstClient);
    }
    if (is_resource($firstPeer)) {
        fclose($firstPeer);
    }
    if (is_resource($secondClient)) {
        fclose($secondClient);
    }
    if (is_resource($secondPeer)) {
        fclose($secondPeer);
    }
});

test('stream select engine shuts down cleanly on SIGTERM without EINTR warnings', function () {
    if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
        skip('pcntl/posix not available');
    }

    [$port, $reservedSocket] = reserveTestPort();
    socket_close($reservedSocket);

    $fixture = dirname(__DIR__) . '/fixtures/run_server.php';
    $command = sprintf(
        '%s %s %d %d %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($fixture),
        $port,
        10,
        escapeshellarg('stream_select'),
        escapeshellarg('default'),
    );

    $descriptors = [
        ['pipe', 'r'],
        ['pipe', 'w'],
        ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);
    expect($process)->toBeResource();

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $deadline = microtime(true) + 10.0;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);
        expect($status['running'])->toBeTrue();

        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($probe !== false) {
            fclose($probe);
            break;
        }

        usleep(20_000);
    }

    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    expect($socket)->not->toBeFalse();
    fclose($socket);

    $status = proc_get_status($process);
    posix_kill($status['pid'], SIGTERM);

    $stderr = '';
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $chunk = fread($pipes[2], 8192);
        if (is_string($chunk) && $chunk !== '') {
            $stderr .= $chunk;
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }

        usleep(20_000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect($stderr)->not->toContain('Interrupted system call');
});

test('stream select engine accepts tcp connections in integration', function () {
    [$port, $reservedSocket] = reserveTestPort();
    socket_close($reservedSocket);

    $process = BackgroundServer::start($port, ['max_connections' => 10]);

    try {
        $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2);
        expect($socket)->not->toBeFalse();

        fclose($socket);
    } finally {
        BackgroundServer::stop($process);
    }
});

test('stream select engine integration enforces max_connections', function () {
    [$port, $reservedSocket] = reserveTestPort();
    socket_close($reservedSocket);

    $process = BackgroundServer::start($port, ['max_connections' => 2]);

    $sockets = [];

    try {
        for ($i = 0; $i < 3; $i++) {
            $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2);
            expect($socket)->not->toBeFalse();
            $sockets[] = $socket;
            usleep(20_000);
        }

        $openCount = 0;
        foreach ($sockets as $socket) {
            if (!@feof($socket)) {
                $openCount++;
            }
        }

        expect($openCount)->toBeLessThanOrEqual(2);
    } finally {
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }

        BackgroundServer::stop($process);
    }
});
