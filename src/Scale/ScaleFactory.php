<?php

namespace Sockeon\Sockeon\Scale;

use Sockeon\Sockeon\Config\ScaleConfig;
use Sockeon\Sockeon\Config\ServerConfig;
use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Contracts\Namespace\NamespaceManagerInterface;
use Sockeon\Sockeon\Contracts\Publisher\PublisherInterface;
use Sockeon\Sockeon\Core\NamespaceManager;
use Sockeon\Sockeon\Core\RedisClientDataStore;
use Sockeon\Sockeon\Core\RedisFactory;
use Sockeon\Sockeon\Core\RedisNamespaceManager;
use Sockeon\Sockeon\Publisher\LocalPublisher;
use Sockeon\Sockeon\Publisher\RedisPublisher;

class ScaleFactory
{
    public static function createNamespaceManager(
        ScaleConfig $config,
        ?RedisClientDataStore $clientDataStore = null,
    ): NamespaceManagerInterface {
        if ($config->isRedisRegistry()) {
            return new RedisNamespaceManager($config, RedisFactory::connect($config), $clientDataStore);
        }

        return new NamespaceManager();
    }

    public static function createPublisher(Server $server, ServerConfig $config): PublisherInterface
    {
        $scaleConfig = $config->getScaleConfig();
        $localPublisher = new LocalPublisher($server);

        if ($scaleConfig->isRedisPublisher()) {
            return new RedisPublisher($localPublisher, $scaleConfig, $server->getLogger());
        }

        return $localPublisher;
    }
}
