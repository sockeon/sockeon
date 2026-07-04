<?php

namespace Sockeon\Sockeon\Core;

use Redis;
use Sockeon\Sockeon\Config\ScaleConfig;

class RedisClientDataStore
{
    public function __construct(
        private ScaleConfig $config,
        private Redis $redis,
    ) {}

    public function set(string $clientId, string $key, mixed $value): void
    {
        $redisKey = $this->key($clientId);
        $this->redis->hSet($redisKey, $key, serialize($value));
        $this->redis->expire($redisKey, $this->config->getPresenceTtl());
    }

    public function get(string $clientId, ?string $key = null): mixed
    {
        $redisKey = $this->key($clientId);

        if ($key === null) {
            $fields = $this->redis->hGetAll($redisKey);
            if (!is_array($fields) || $fields === []) {
                return null;
            }

            $data = [];
            foreach ($fields as $field => $value) {
                if (!is_string($field) || !is_string($value)) {
                    continue;
                }

                $data[$field] = unserialize($value);
            }

            return $data === [] ? null : $data;
        }

        $value = $this->redis->hGet($redisKey, $key);

        return is_string($value) ? unserialize($value) : null;
    }

    public function has(string $clientId): bool
    {
        return (int) $this->redis->exists($this->key($clientId)) > 0;
    }

    public function delete(string $clientId): void
    {
        $this->redis->del($this->key($clientId));
    }

    public function forget(string $clientId, ?string $key = null): void
    {
        if ($key === null) {
            $this->delete($clientId);

            return;
        }

        $this->redis->hDel($this->key($clientId), $key);
    }

    private function key(string $clientId): string
    {
        return $this->config->getRedisPrefix() . 'client:' . $clientId . ':data';
    }
}
