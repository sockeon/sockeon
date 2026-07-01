<?php

use Sockeon\Sockeon\Config\ScaleConfig;
use Sockeon\Sockeon\Config\ServerConfig;
use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Core\NamespaceManager;
use Sockeon\Sockeon\Core\RedisNamespaceManager;
use Sockeon\Sockeon\Publisher\LocalPublisher;
use Sockeon\Sockeon\Scale\ScaleFactory;

test('scale config defaults to local publisher and registry', function () {
    $config = new ScaleConfig();

    expect($config->getNodeId())->toBe('node-1')
        ->and($config->getPublisher())->toBe('local')
        ->and($config->getRegistry())->toBe('local')
        ->and($config->isRedisPublisher())->toBeFalse()
        ->and($config->isRedisRegistry())->toBeFalse()
        ->and($config->getRedisChannel())->toBe('sockeon:broadcast')
        ->and($config->getPresenceTtl())->toBe(300);
});

test('scale config accepts redis cluster settings', function () {
    $config = new ScaleConfig([
        'node_id' => 'node-west-2',
        'publisher' => 'redis',
        'registry' => 'redis',
        'presence_ttl' => 120,
        'redis' => [
            'host' => '10.0.0.5',
            'port' => 6380,
            'channel' => 'app:broadcast',
            'prefix' => 'app:',
        ],
    ]);

    expect($config->getNodeId())->toBe('node-west-2')
        ->and($config->isRedisPublisher())->toBeTrue()
        ->and($config->isRedisRegistry())->toBeTrue()
        ->and($config->getRedisHost())->toBe('10.0.0.5')
        ->and($config->getRedisPort())->toBe(6380)
        ->and($config->getRedisChannel())->toBe('app:broadcast')
        ->and($config->getRedisPrefix())->toBe('app:')
        ->and($config->getPresenceTtl())->toBe(120);
});

test('server config exposes scale settings', function () {
    $serverConfig = new ServerConfig([
        'scale' => ['node_id' => 'node-a'],
    ]);

    expect($serverConfig->getScaleConfig()->getNodeId())->toBe('node-a');
});

test('server uses local publisher and namespace manager by default', function () {
    $server = new Server(new ServerConfig());

    expect($server->getPublisher())->toBeInstanceOf(LocalPublisher::class)
        ->and($server->getNamespaceManager())->toBeInstanceOf(NamespaceManager::class)
        ->and($server->getScaleConfig()->getPublisher())->toBe('local');
});

test('scale factory creates redis namespace manager when configured', function () {
    if (!extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis not available (enable igbinary + redis in php.ini)');
    }

    try {
        $manager = ScaleFactory::createNamespaceManager(new ScaleConfig([
            'registry' => 'redis',
            'redis' => ['database' => 15],
        ]));
    } catch (Throwable $e) {
        test()->markTestSkipped('Redis not reachable: ' . $e->getMessage());
    }

    expect($manager)->toBeInstanceOf(RedisNamespaceManager::class);
});

test('redis namespace manager tracks local room membership', function () {
    if (!extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis not available (enable igbinary + redis in php.ini)');
    }

    $scaleConfig = new ScaleConfig([
        'node_id' => 'test-node',
        'registry' => 'redis',
        'redis' => [
            'database' => 15,
            'prefix' => 'sockeon:test:' . uniqid('', true) . ':',
        ],
    ]);

    try {
        $manager = ScaleFactory::createNamespaceManager($scaleConfig);
    } catch (Throwable $e) {
        test()->markTestSkipped('Redis not reachable: ' . $e->getMessage());
    }

    expect($manager)->toBeInstanceOf(RedisNamespaceManager::class);

    $manager->joinNamespace('client-1', '/');
    $manager->joinRoom('client-1', 'lobby', '/');

    expect($manager->getClientsInRoom('lobby', '/'))->toBe(['client-1' => 'client-1'])
        ->and($manager->getClientRooms('client-1'))->toBe(['lobby']);

    $manager->cleanup('client-1');

    expect($manager->getClientsInRoom('lobby', '/'))->toBe([]);
});
