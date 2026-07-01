<?php

namespace Sockeon\Sockeon\Engine;

use RuntimeException;
use Sockeon\Sockeon\Config\ServerConfig;
use Sockeon\Sockeon\Contracts\Engine\EngineInterface;

class EngineFactory
{
    public static function create(ServerConfig $config): EngineInterface
    {
        return match ($config->getEngine()) {
            'swoole' => self::createSwooleEngine($config),
            'stream_select' => new StreamSelectEngine(),
            default => throw new RuntimeException("Unknown engine: {$config->getEngine()}"),
        };
    }

    private static function createSwooleEngine(ServerConfig $config): SwooleEngine
    {
        if (!class_exists(\Swoole\WebSocket\Server::class)) {
            throw new RuntimeException(
                'The Swoole extension is required for engine=swoole. Install ext-swoole or ext-openswoole.'
            );
        }

        return new SwooleEngine($config->getSwooleEngineConfig());
    }
}
