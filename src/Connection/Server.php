<?php

namespace Sockeon\Sockeon\Connection;

use RuntimeException;
use Sockeon\Sockeon\Config\ServerConfig;
use Sockeon\Sockeon\Contracts\LoggerInterface;
use Sockeon\Sockeon\Core\Middleware;
use Sockeon\Sockeon\Core\NamespaceManager;
use Sockeon\Sockeon\Core\Router;
use Sockeon\Sockeon\Http\Handler as HttpHandler;
use Sockeon\Sockeon\WebSocket\Handler as WebSocketHandler;
use Sockeon\Sockeon\Traits\Server\HandlesClients;
use Sockeon\Sockeon\Traits\Server\HandlesConfiguration;
use Sockeon\Sockeon\Traits\Server\HandlesControllers;
use Sockeon\Sockeon\Traits\Server\HandlesHttpWs;
use Sockeon\Sockeon\Traits\Server\HandlesLogging;
use Sockeon\Sockeon\Traits\Server\HandlesMiddlewares;
use Sockeon\Sockeon\Traits\Server\HandlesNamespace;
use Sockeon\Sockeon\Traits\Server\HandlesQueue;
use Sockeon\Sockeon\Traits\Server\HandlesRooms;
use Sockeon\Sockeon\Traits\Server\HandlesRouting;
use Sockeon\Sockeon\Traits\Server\HandlesSendBroadcast;

class Server
{
    use HandlesConfiguration, HandlesClients, HandlesMiddlewares, HandlesControllers, HandlesHttpWs, HandlesQueue, HandlesRooms, HandlesSendBroadcast, HandlesLogging, HandlesRouting, HandlesNamespace;

    protected string $host;
    
    protected int $port;

    /** @var resource|false */
    protected $socket;

    /** @var array<int, resource> */
    protected array $clients = [];

    /** @var array<int, string> */
    protected array $clientTypes = [];

    /** @var array<int, array<string, mixed>> */
    protected array $clientData = [];

    protected Router $router;

    protected WebSocketHandler $wsHandler;

    protected HttpHandler $httpHandler;
    
    protected NamespaceManager $namespaceManager;

    protected Middleware $middleware;

    protected bool $isDebug;
    
    protected LoggerInterface $logger;

    public function __construct(ServerConfig $config)
    {
        $this->applyServerConfig($config);
        $this->initializeCoreComponents($config);
    }

    public function run(): void
    {
        $this->startSocket();
        $this->loop();
    }
}
