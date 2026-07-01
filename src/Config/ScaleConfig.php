<?php

namespace Sockeon\Sockeon\Config;

class ScaleConfig
{
    protected string $nodeId = 'node-1';

    protected string $publisher = 'local';

    protected string $registry = 'local';

    protected string $redisHost = '127.0.0.1';

    protected int $redisPort = 6379;

    protected ?string $redisPassword = null;

    protected int $redisDatabase = 0;

    protected string $redisChannel = 'sockeon:broadcast';

    protected string $redisPrefix = 'sockeon:';

    protected int $presenceTtl = 300;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        if (isset($config['node_id']) && is_string($config['node_id']) && $config['node_id'] !== '') {
            $this->nodeId = $config['node_id'];
        }

        if (isset($config['publisher']) && is_string($config['publisher'])) {
            $this->publisher = $config['publisher'];
        }

        if (isset($config['registry']) && is_string($config['registry'])) {
            $this->registry = $config['registry'];
        }

        if (isset($config['presence_ttl']) && is_numeric($config['presence_ttl'])) {
            $this->presenceTtl = max(1, (int) $config['presence_ttl']);
        }

        if (isset($config['redis']) && is_array($config['redis'])) {
            $this->applyRedisConfig($config['redis']);
        }
    }

    /**
     * @param array<string, mixed> $redis
     */
    private function applyRedisConfig(array $redis): void
    {
        if (isset($redis['host']) && is_string($redis['host'])) {
            $this->redisHost = $redis['host'];
        }

        if (isset($redis['port']) && is_numeric($redis['port'])) {
            $this->redisPort = (int) $redis['port'];
        }

        if (isset($redis['password']) && is_string($redis['password'])) {
            $this->redisPassword = $redis['password'];
        }

        if (isset($redis['database']) && is_numeric($redis['database'])) {
            $this->redisDatabase = (int) $redis['database'];
        }

        if (isset($redis['channel']) && is_string($redis['channel']) && $redis['channel'] !== '') {
            $this->redisChannel = $redis['channel'];
        }

        if (isset($redis['prefix']) && is_string($redis['prefix']) && $redis['prefix'] !== '') {
            $this->redisPrefix = $redis['prefix'];
        }
    }

    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    public function getPublisher(): string
    {
        return $this->publisher;
    }

    public function getRegistry(): string
    {
        return $this->registry;
    }

    public function isRedisPublisher(): bool
    {
        return $this->publisher === 'redis';
    }

    public function isRedisRegistry(): bool
    {
        return $this->registry === 'redis';
    }

    public function getRedisHost(): string
    {
        return $this->redisHost;
    }

    public function getRedisPort(): int
    {
        return $this->redisPort;
    }

    public function getRedisPassword(): ?string
    {
        return $this->redisPassword;
    }

    public function getRedisDatabase(): int
    {
        return $this->redisDatabase;
    }

    public function getRedisChannel(): string
    {
        return $this->redisChannel;
    }

    public function getRedisPrefix(): string
    {
        return $this->redisPrefix;
    }

    public function getPresenceTtl(): int
    {
        return $this->presenceTtl;
    }
}
