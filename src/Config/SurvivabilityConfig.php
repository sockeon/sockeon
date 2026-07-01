<?php

namespace Sockeon\Sockeon\Config;

class SurvivabilityConfig
{
    protected int $maxConnections = 10000;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        if (isset($config['max_connections']) && is_int($config['max_connections'])) {
            $this->maxConnections = $config['max_connections'];
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
}
