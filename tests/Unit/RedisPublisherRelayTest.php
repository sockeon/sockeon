<?php

use Sockeon\Sockeon\Publisher\RedisPublisher;

test('redis publisher relays to sibling workers on the same node', function () {
    expect(RedisPublisher::shouldRelayPipeBroadcast('node-1', 0, 'node-1', 0))->toBeFalse()
        ->and(RedisPublisher::shouldRelayPipeBroadcast('node-1', 1, 'node-1', 0))->toBeTrue()
        ->and(RedisPublisher::shouldRelayPipeBroadcast('node-1', 3, 'node-1', 2))->toBeTrue();
});

test('redis publisher relays to all workers on a remote node', function () {
    expect(RedisPublisher::shouldRelayPipeBroadcast('node-b', 0, 'node-a', 0))->toBeTrue()
        ->and(RedisPublisher::shouldRelayPipeBroadcast('node-b', 4, 'node-a', 2))->toBeTrue();
});

test('redis publisher ignores legacy same-node payloads without origin worker id', function () {
    expect(RedisPublisher::shouldRelayPipeBroadcast('node-1', 1, 'node-1', null))->toBeFalse();
});
