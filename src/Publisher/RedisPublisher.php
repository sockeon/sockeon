<?php

namespace Sockeon\Sockeon\Publisher;

use Redis;
use Sockeon\Sockeon\Config\ScaleConfig;
use Sockeon\Sockeon\Contracts\LoggerInterface;
use Sockeon\Sockeon\Contracts\Publisher\PublisherInterface;
use Sockeon\Sockeon\Core\RedisFactory;

class RedisPublisher implements PublisherInterface
{
    private Redis $redis;

    private ?Redis $subscriber = null;

    public function __construct(
        private LocalPublisher $localPublisher,
        private ScaleConfig $config,
        private LoggerInterface $logger,
        ?Redis $redis = null,
    ) {
        $this->redis = $redis ?? RedisFactory::connect($config);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function broadcast(string $event, array $data, ?string $namespace = null, ?string $room = null): void
    {
        $this->localPublisher->broadcast($event, $data, $namespace, $room);

        $this->redis->publish($this->config->getRedisChannel(), json_encode([
            'event' => $event,
            'data' => $data,
            'namespace' => $namespace,
            'room' => $room,
            'originNodeId' => $this->config->getNodeId(),
        ], JSON_THROW_ON_ERROR));
    }

    public function start(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            $this->logger->warning(
                '[Sockeon Scale] Redis cross-node broadcast subscriber requires engine=swoole; local delivery only.'
            );

            return;
        }

        $this->subscriber = RedisFactory::connect($this->config);
        $channel = $this->config->getRedisChannel();
        $nodeId = $this->config->getNodeId();
        $localPublisher = $this->localPublisher;

        \Swoole\Coroutine::create(function () use ($channel, $nodeId, $localPublisher): void {
            $this->subscriber?->subscribe([$channel], function ($redis, $chan, $message) use ($nodeId, $localPublisher): void {
                $payload = json_decode($message, true);
                if (!is_array($payload)) {
                    return;
                }

                $originNodeId = $payload['originNodeId'] ?? '';
                if (!is_string($originNodeId) || $originNodeId === $nodeId) {
                    return;
                }

                $event = $payload['event'] ?? '';
                if (!is_string($event) || $event === '') {
                    return;
                }

                /** @var array<string, mixed> $data */
                $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
                $namespace = is_string($payload['namespace'] ?? null) ? $payload['namespace'] : null;
                $room = is_string($payload['room'] ?? null) ? $payload['room'] : null;

                $localPublisher->broadcast($event, $data, $namespace, $room);
            });
        });
    }
}
