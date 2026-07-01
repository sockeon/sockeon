<?php

use Sockeon\Sockeon\Config\ServerConfig;
use Sockeon\Sockeon\Config\SwooleEngineConfig;
use Sockeon\Sockeon\Config\SurvivabilityConfig;
use Sockeon\Sockeon\Engine\EngineFactory;
use Sockeon\Sockeon\Engine\StreamSelectEngine;

test('swoole engine config defaults', function () {
    $config = new SwooleEngineConfig();

    expect($config->getWorkerNum())->toBeGreaterThan(0)
        ->and($config->getTaskWorkerNum())->toBe(0)
        ->and($config->getMaxConnection())->toBe(100000)
        ->and($config->useCoroutineDispatch())->toBeTrue();
});

test('survivability config exposes swoole heartbeat settings', function () {
    $config = new SurvivabilityConfig([
        'write_buffer_limit' => 32768,
        'heartbeat_idle_time' => 120,
        'heartbeat_check_interval' => 30,
    ]);

    expect($config->getWriteBufferLimit())->toBe(32768)
        ->and($config->getHeartbeatIdleTime())->toBe(120)
        ->and($config->getHeartbeatCheckInterval())->toBe(30);
});

test('server config defaults to stream_select engine', function () {
    $config = new ServerConfig();

    expect($config->getEngine())->toBe('stream_select')
        ->and(EngineFactory::create($config))->toBeInstanceOf(StreamSelectEngine::class);
});

test('engine factory throws when swoole extension is missing', function () {
    if (class_exists(\Swoole\WebSocket\Server::class)) {
        expect(true)->toBeTrue();

        return;
    }

    $config = new ServerConfig(['engine' => 'swoole']);

    expect(fn() => EngineFactory::create($config))
        ->toThrow(RuntimeException::class, 'Swoole extension is required');
});
