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

    private bool $subscriberStarted = false;

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
        if (class_exists(\Swoole\Coroutine::class)) {
            return;
        }

        $this->logger->warning(
            '[Sockeon Scale] Redis cross-node broadcast subscriber requires engine=swoole; local delivery only.'
        );
    }

    public function registerSwooleSubscriber(\Swoole\Server $server): void
    {
        if ($this->subscriberStarted) {
            return;
        }

        $this->subscriberStarted = true;

        $config = $this->config;
        $channel = $config->getRedisChannel();
        $nodeId = $config->getNodeId();
        $localPublisher = $this->localPublisher;

        $process = new \Swoole\Process(function (\Swoole\Process $worker) use ($config, $channel): void {
            $redis = RedisFactory::connect($config);
            $redis->subscribe([$channel], function ($redis, $chan, $message) use ($worker): void {
                if (is_string($message)) {
                    $worker->write($message);
                }
            });
        });

        $server->addProcess($process);

        $server->on('PipeMessage', function (\Swoole\Server $server, int $workerId, $data) use ($nodeId, $localPublisher): void {
            if (!is_string($data)) {
                return;
            }

            $payload = json_decode($data, true);
            if (!is_array($payload)) {
                return;
            }

            $originNodeId = $payload['originNodeId'] ?? '';
            if (!is_string($originNodeId) || $originNodeId === '' || $originNodeId === $nodeId) {
                return;
            }

            $event = $payload['event'] ?? '';
            if (!is_string($event) || $event === '') {
                return;
            }

            /** @var array<string, mixed> $eventData */
            $eventData = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $namespace = is_string($payload['namespace'] ?? null) ? $payload['namespace'] : null;
            $room = is_string($payload['room'] ?? null) ? $payload['room'] : null;

            $localPublisher->broadcast($event, $eventData, $namespace, $room);
        });
    }
}
