<?php

namespace Sockeon\Sockeon\Config;

class SurvivabilityConfig
{
    protected int $maxConnections = 10000;

    protected int $writeBufferLimit = 65536;

    protected int $heartbeatIdleTime = 600;

    protected int $heartbeatCheckInterval = 60;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        if (isset($config['max_connections']) && is_numeric($config['max_connections'])) {
            $this->maxConnections = (int) $config['max_connections'];
        }

        if (isset($config['write_buffer_limit']) && is_numeric($config['write_buffer_limit'])) {
            $this->writeBufferLimit = (int) $config['write_buffer_limit'];
        }

        if (isset($config['heartbeat_idle_time']) && is_numeric($config['heartbeat_idle_time'])) {
            $this->heartbeatIdleTime = (int) $config['heartbeat_idle_time'];
        }

        if (isset($config['heartbeat_check_interval']) && is_numeric($config['heartbeat_check_interval'])) {
            $this->heartbeatCheckInterval = (int) $config['heartbeat_check_interval'];
        }
    }

    public function getMaxConnections(): int
    {
        return $this->maxConnections;
    }

    public function setMaxConnections(int $maxConnections): void
    {
        $this->maxConnections = $maxConnections;
    }

    public function getWriteBufferLimit(): int
    {
        return $this->writeBufferLimit;
    }

    public function getHeartbeatIdleTime(): int
    {
        return $this->heartbeatIdleTime;
    }

    public function getHeartbeatCheckInterval(): int
    {
        return $this->heartbeatCheckInterval;
    }
}
