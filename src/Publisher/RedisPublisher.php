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

    private ?\Swoole\Server $swooleServer = null;

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
            'originWorkerId' => $this->originWorkerId(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Whether a worker should deliver a pipe-relayed broadcast.
     *
     * @internal
     */
    public static function shouldRelayPipeBroadcast(
        string $localNodeId,
        int $localWorkerId,
        string $originNodeId,
        ?int $originWorkerId,
    ): bool {
        if ($originNodeId === '') {
            return false;
        }

        if ($originNodeId !== $localNodeId) {
            return true;
        }

        if ($originWorkerId === null) {
            return false;
        }

        return $originWorkerId !== $localWorkerId;
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
        $this->swooleServer = $server;

        if ($this->subscriberStarted) {
            return;
        }

        $this->subscriberStarted = true;

        $config = $this->config;
        $channel = $config->getRedisChannel();
        $nodeId = $config->getNodeId();
        $localPublisher = $this->localPublisher;

        $process = new \Swoole\Process(function () use ($server, $config, $channel): void {
            $redis = RedisFactory::connect($config);
            $redis->subscribe([$channel], function ($redis, $chan, $message) use ($server): void {
                if (!is_string($message)) {
                    return;
                }

                $workerNum = (int) ($server->setting['worker_num'] ?? 1);
                for ($i = 0; $i < $workerNum; $i++) {
                    $server->sendMessage($message, $i);
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
            if (!is_string($originNodeId)) {
                return;
            }

            $originWorkerId = array_key_exists('originWorkerId', $payload)
                ? (int) $payload['originWorkerId']
                : null;

            if (!self::shouldRelayPipeBroadcast($nodeId, $workerId, $originNodeId, $originWorkerId)) {
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

    private function originWorkerId(): int
    {
        if ($this->swooleServer !== null) {
            return (int) ($this->swooleServer->worker_id ?? 0);
        }

        return 0;
    }
}
