<?php

namespace Sockeon\Sockeon\Core;

use Redis;
use RuntimeException;
use Sockeon\Sockeon\Config\ScaleConfig;

class RedisFactory
{
    public static function connect(ScaleConfig $config): Redis
    {
        if (!class_exists(Redis::class)) {
            throw new RuntimeException(
                'The Redis extension is required for scale.publisher=redis or scale.registry=redis.'
            );
        }

        $redis = new Redis();
        $connected = $redis->connect($config->getRedisHost(), $config->getRedisPort(), 2.0);

        if ($connected !== true) {
            throw new RuntimeException(
                "Failed to connect to Redis at {$config->getRedisHost()}:{$config->getRedisPort()}"
            );
        }

        if ($config->getRedisPassword() !== null) {
            $redis->auth($config->getRedisPassword());
        }

        if ($config->getRedisDatabase() > 0) {
            $redis->select($config->getRedisDatabase());
        }

        return $redis;
    }
}
