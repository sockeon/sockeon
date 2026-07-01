<?php

use Sockeon\Sockeon\Config\RateLimitConfig;
use Sockeon\Sockeon\Config\ServerConfig;
use Sockeon\Sockeon\Config\SurvivabilityConfig;
use Sockeon\Sockeon\Connection\Server;

test('survivability config defaults max_connections to 10000', function () {
    $config = new SurvivabilityConfig();

    expect($config->getMaxConnections())->toBe(10000);
});

test('survivability config accepts custom max_connections', function () {
    $config = new SurvivabilityConfig(['max_connections' => 500]);

    expect($config->getMaxConnections())->toBe(500);
});

test('server exposes survivability max_connections', function () {
    $serverConfig = new ServerConfig([
        'survivability' => ['max_connections' => 250],
    ]);

    $server = new Server($serverConfig);

    expect($server->getMaxConnections())->toBe(250);
});

test('connection rate limits reject when global cap is reached', function () {
    $serverConfig = new ServerConfig([
        'rate_limit' => [
            'maxGlobalConnections' => 0,
            'maxConnectionsPerIp' => 10,
        ],
    ]);

    $server = new Server($serverConfig);

    $socketPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($socketPair)->not->toBeFalse();

    [$client, $peer] = $socketPair;

    $reflection = new ReflectionClass($server);
    $method = $reflection->getMethod('isConnectionAllowed');

    expect($method->invoke($server, '127.0.0.1'))->toBeFalse();

    fclose($client);
    fclose($peer);
});

test('connection rate limits reject when per-ip cap is reached', function () {
    $serverConfig = new ServerConfig([
        'rate_limit' => [
            'maxGlobalConnections' => 100,
            'maxConnectionsPerIp' => 2,
        ],
    ]);

    $server = new Server($serverConfig);

    $reflection = new ReflectionClass($server);
    $property = $reflection->getProperty('connectionsPerIp');
    $property->setValue($server, ['10.0.0.1' => 2]);

    $method = $reflection->getMethod('isConnectionAllowed');

    expect($method->invoke($server, '10.0.0.1'))->toBeFalse()
        ->and($method->invoke($server, '10.0.0.2'))->toBeTrue();
});

test('whitelisted ips bypass connection rate limits', function () {
    $serverConfig = new ServerConfig([
        'rate_limit' => new RateLimitConfig([
            'maxGlobalConnections' => 0,
            'maxConnectionsPerIp' => 0,
            'whitelist' => ['127.0.0.1'],
        ]),
    ]);

    $server = new Server($serverConfig);

    $reflection = new ReflectionClass($server);
    $method = $reflection->getMethod('isConnectionAllowed');

    expect($method->invoke($server, '127.0.0.1'))->toBeTrue();
});
