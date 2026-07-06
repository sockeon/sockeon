<?php

use Sockeon\Sockeon\Config\ScaleConfig;
use Sockeon\Sockeon\Config\ServerConfig;
use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Core\RedisFactory;
use Sockeon\Sockeon\Logging\Logger;
use Sockeon\Sockeon\Publisher\LocalPublisher;
use Sockeon\Sockeon\Publisher\RedisPublisher;

test('redis publisher publishes cross-node broadcast payload', function () {
    if (!extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis not available (enable igbinary + redis in php.ini)');
    }

    $channel = 'sockeon:test:' . uniqid('', true);
    $scaleConfig = new ScaleConfig([
        'node_id' => 'node-a',
        'publisher' => 'redis',
        'redis' => [
            'channel' => $channel,
            'database' => 15,
        ],
    ]);

    try {
        $redis = RedisFactory::connect($scaleConfig);
        expect($redis->ping())->toBeTrue();
    } catch (Throwable $e) {
        test()->markTestSkipped('Redis not reachable: ' . $e->getMessage());
    }

    $outFile = tempnam(sys_get_temp_dir(), 'sockeon_redis_');
    expect($outFile)->not->toBeFalse();

    $captureScript = dirname(__DIR__) . '/fixtures/redis_capture.php';
    $capture = proc_open(
        [
            PHP_BINARY,
            '-d',
            'extension=igbinary',
            '-d',
            'extension=redis',
            $captureScript,
            $channel,
            $outFile,
            '15',
        ],
        [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ],
        $capturePipes
    );

    expect($capture)->toBeResource();

    foreach ($capturePipes as $pipe) {
        fclose($pipe);
    }

    usleep(300_000);

    $logger = new Logger();
    $logger->setLogToConsole(false);
    $logger->setLogToFile(false);

    $serverConfig = new ServerConfig(['scale' => ['node_id' => 'node-a', 'publisher' => 'redis', 'redis' => [
        'channel' => $channel,
        'database' => 15,
    ]]]);
    $serverConfig->setLogger($logger);

    $server = new Server($serverConfig);
    $localPublisher = new LocalPublisher($server);
    $publisher = new RedisPublisher($localPublisher, $scaleConfig, $logger, RedisFactory::connect($scaleConfig));

    $publisher->broadcast('room.message', ['message' => 'hello'], '/', 'lobby');

    $deadline = microtime(true) + 5.0;
    do {
        if (is_file($outFile) && filesize($outFile) > 0) {
            break;
        }

        usleep(100_000);
    } while (microtime(true) < $deadline);

    proc_terminate($capture);
    proc_close($capture);

    $raw = is_file($outFile) ? file_get_contents($outFile) : false;
    @unlink($outFile);

    expect($raw)->not->toBeFalse();

    $payload = json_decode((string) $raw, true);
    expect($payload)->toBeArray()
        ->and($payload['event'] ?? null)->toBe('room.message')
        ->and($payload['data']['message'] ?? null)->toBe('hello')
        ->and($payload['namespace'] ?? null)->toBe('/')
        ->and($payload['room'] ?? null)->toBe('lobby')
        ->and($payload['originNodeId'] ?? null)->toBe('node-a')
        ->and($payload['originWorkerId'] ?? null)->toBe(0);
});
