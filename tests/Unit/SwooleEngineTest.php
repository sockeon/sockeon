<?php

use Sockeon\Sockeon\Config\SwooleEngineConfig;
use Sockeon\Sockeon\Engine\EngineFactory;
use Sockeon\Sockeon\Engine\SwooleEngine;
use Sockeon\Sockeon\Engine\SwooleEngineTables;
use Sockeon\Sockeon\Config\ServerConfig;
use Sockeon\Sockeon\Registry\SwooleTableClientRegistry;

test('swoole engine factory requires extension', function () {
    if (class_exists(\Swoole\WebSocket\Server::class)) {
        expect(EngineFactory::create(new ServerConfig(['engine' => 'swoole'])))->toBeInstanceOf(SwooleEngine::class);

        return;
    }

    expect(fn () => EngineFactory::create(new ServerConfig(['engine' => 'swoole'])))
        ->toThrow(RuntimeException::class, 'Swoole extension is required');
});

test('swoole engine reports transport metadata', function () {
    if (!class_exists(\Swoole\WebSocket\Server::class)) {
        test()->markTestSkipped('Swoole extension not available');
    }

    $engine = new SwooleEngine(new SwooleEngineConfig());

    expect($engine->getName())->toBe('swoole')
        ->and($engine->framesOutboundWebSocket())->toBeFalse();
});

test('swoole table client registry maps fd and client id bidirectionally', function () {
    if (!class_exists(\Swoole\Table::class)) {
        test()->markTestSkipped('Swoole extension not available');
    }

    $tables = SwooleEngineTables::create(new SwooleEngineConfig(['client_table_size' => 1024]));
    $registry = new SwooleTableClientRegistry($tables);

    $clientId = $registry->generateClientId();
    $registry->registerConnection($clientId, 42, 'ws', 3);

    expect($registry->has($clientId))->toBeTrue()
        ->and($registry->getFd($clientId))->toBe(42)
        ->and($registry->getType($clientId))->toBe('ws')
        ->and($registry->getClientIdByResource(42))->toBe($clientId)
        ->and($registry->count())->toBe(1)
        ->and($registry->ids())->toBe([$clientId]);

    $registry->setType($clientId, 'http');
    expect($registry->getType($clientId))->toBe('http');

    $registry->remove($clientId);

    expect($registry->has($clientId))->toBeFalse()
        ->and($registry->getFd($clientId))->toBeNull()
        ->and($registry->getClientIdByResource(42))->toBeNull()
        ->and($registry->count())->toBe(0);
});

test('swoole table client registry supports add alias for fd resources', function () {
    if (!class_exists(\Swoole\Table::class)) {
        test()->markTestSkipped('Swoole extension not available');
    }

    $tables = SwooleEngineTables::create(new SwooleEngineConfig(['client_table_size' => 64]));
    $registry = new SwooleTableClientRegistry($tables);
    $clientId = $registry->generateClientId();

    $registry->add($clientId, 99, 'ws');

    expect($registry->getResource($clientId))->toBe(99)
        ->and($registry->all())->toBe([$clientId => 99]);
});
