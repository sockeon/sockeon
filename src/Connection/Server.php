<?php

namespace Sockeon\Sockeon\Connection;

use RuntimeException;
use Sockeon\Sockeon\Config\RateLimitConfig;
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

    protected ?RateLimitConfig $rateLimitConfig = null;

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

    /**
     * Get all connected clients
     * 
     * @return array<int, resource> Array of client IDs and their resources
     */
    public function getClients(): array
    {
        return $this->clients;
    }

    /**
     * Get client types
     * 
     * @return array<int, string> Array of client IDs and their types
     */
    public function getClientTypes(): array
    {
        return $this->clientTypes;
    }

    /**
     * Get client IDs only
     * 
     * @return array<int, int> Array of client IDs
     */
    public function getClientIds(): array
    {
        return array_keys($this->clients);
    }

    /**
     * Get the number of connected clients
     * 
     * @return int Number of connected clients
     */
    public function getClientCount(): int
    {
        return count($this->clients);
    }

    /**
     * Check if a client is connected
     * 
     * @param int $clientId The client ID to check
     * @return bool True if connected, false otherwise
     */
    public function isClientConnected(int $clientId): bool
    {
        return isset($this->clients[$clientId]);
    }

    /**
     * Get the type of a specific client
     * 
     * @param int $clientId The client ID to check
     * @return string|null The client type or null if not found
     */
    public function getClientType(int $clientId): ?string
    {
        return $this->clientTypes[$clientId] ?? null;
    }

    /**
     * Get the rate limiting configuration
     * 
     * @return RateLimitConfig|null The rate limiting configuration or null if disabled
     */
    public function getRateLimitConfig(): ?RateLimitConfig
    {
        return $this->rateLimitConfig;
    }

    /**
     * Check if rate limiting is enabled
     * 
     * @return bool True if rate limiting is enabled, false otherwise
     */
    public function isRateLimitingEnabled(): bool
    {
        return $this->rateLimitConfig !== null && $this->rateLimitConfig->isEnabled();
    }
}
