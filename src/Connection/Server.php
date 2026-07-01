<?php

namespace Sockeon\Sockeon\Connection;

use Sockeon\Sockeon\Config\RateLimitConfig;
use Sockeon\Sockeon\Config\ServerConfig;
use Sockeon\Sockeon\Contracts\Engine\EngineInterface;
use Sockeon\Sockeon\Contracts\LoggerInterface;
use Sockeon\Sockeon\Engine\StreamSelectEngine;
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
    use HandlesConfiguration;
    use HandlesClients;
    use HandlesMiddlewares;
    use HandlesControllers;
    use HandlesHttpWs;
    use HandlesQueue;
    use HandlesRooms;
    use HandlesSendBroadcast;
    use HandlesLogging;
    use HandlesRouting;
    use HandlesNamespace;

    protected string $host;

    protected int $port;

    protected EngineInterface $engine;

    /** @var array<string, resource> */
    protected array $clients = [];

    /** @var array<string, string> */
    protected array $clientTypes = [];

    /** @var array<string, array<string, mixed>> */
    protected array $clientData = [];

    /** @var array<int, string> Maps resource ID to unique client ID */
    protected array $resourceToClientId = [];

    /** @var int Counter for generating sequential part of client ID */
    protected int $clientIdCounter = 0;

    protected Router $router;

    protected WebSocketHandler $wsHandler;

    protected HttpHandler $httpHandler;

    protected NamespaceManager $namespaceManager;

    protected Middleware $middleware;

    protected bool $isDebug;

    protected LoggerInterface $logger;

    protected ?RateLimitConfig $rateLimitConfig = null;

    protected ?string $healthCheckPath = null;

    protected int $maxMessageSize = 65536; // 64KB

    /**
     * Server start time (Unix timestamp with microseconds)
     *
     * @var float|null
     */
    protected ?float $startTime = null;

    public function __construct(ServerConfig $config, ?EngineInterface $engine = null)
    {
        $this->applyServerConfig($config);
        $this->initializeCoreComponents($config);
        $this->engine = $engine ?? new StreamSelectEngine();
        $this->engine->setServer($this);
    }

    public function run(): void
    {
        $this->engine->start();
    }

    public function getEngine(): EngineInterface
    {
        return $this->engine;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @return resource|null
     */
    public function getClientResource(string $clientId)
    {
        $client = $this->clients[$clientId] ?? null;

        return is_resource($client) ? $client : null;
    }

    /**
     * Get all connected clients
     *
     * @return array<string, resource> Array of client IDs and their resources
     */
    public function getClients(): array
    {
        return $this->clients;
    }

    /**
     * Get client types
     *
     * @return array<string, string> Array of client IDs and their types
     */
    public function getClientTypes(): array
    {
        return $this->clientTypes;
    }

    /**
     * Get the maximum message size
     *
     * @return int
     */
    public function getMaxMessageSize(): int
    {
        return $this->maxMessageSize;
    }

    /**
     * Generate a unique client ID
     *
     * @return string Unique client identifier
     */
    protected function generateClientId(): string
    {
        $this->clientIdCounter++;
        // Format: sockeon_{timestamp}_{counter}_{random}
        return sprintf(
            'sockeon_%s_%d_%s',
            base_convert((string) microtime(true), 10, 36),
            $this->clientIdCounter,
            bin2hex(random_bytes(4))
        );
    }

    /**
     * Get client ID from resource
     *
     * @param resource $resource
     * @return string|null
     */
    protected function getClientIdFromResource($resource): ?string
    {
        $resourceId = (int) $resource;
        return $this->resourceToClientId[$resourceId] ?? null;
    }

    /**
     * Get all client IDs
     *
     * @return list<string> Array of client IDs
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
     * Check if a client is currently connected
     *
     * @param string $clientId The client ID to check
     * @return bool True if connected, false otherwise
     */
    public function isClientConnected(string $clientId): bool
    {
        return isset($this->clients[$clientId]);
    }

    /**
     * Get the type of a specific client
     *
     * @param string $clientId The client ID to check
     * @return string|null The client type or null if not found
     */
    public function getClientType(string $clientId): ?string
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

    /**
     * Get the health check endpoint path
     *
     * @return string|null The health check path or null if disabled
     */
    public function getHealthCheckPath(): ?string
    {
        return $this->healthCheckPath;
    }

    /**
     * Get server start time
     *
     * @return float|null Unix timestamp with microseconds when server started, or null if not started
     */
    public function getStartTime(): ?float
    {
        return $this->startTime;
    }

    /**
     * Get server uptime in seconds
     *
     * @return int|null Server uptime in seconds, or null if server hasn't started
     */
    public function getUptime(): ?int
    {
        if ($this->startTime === null) {
            return null;
        }

        return (int) (microtime(true) - $this->startTime);
    }

    /**
     * Get server uptime as a human-readable string
     *
     * @return string|null Human-readable uptime string (e.g., "2h 30m 15s"), or null if not started
     */
    public function getUptimeString(): ?string
    {
        $uptime = $this->getUptime();
        if ($uptime === null) {
            return null;
        }

        $seconds = $uptime % 60;
        $minutes = (int) (($uptime / 60) % 60);
        $hours = (int) (($uptime / 3600) % 24);
        $days = (int) ($uptime / 86400);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        if ($minutes > 0) {
            $parts[] = $minutes . 'm';
        }
        if ($seconds > 0 || empty($parts)) {
            $parts[] = $seconds . 's';
        }

        return implode(' ', $parts);
    }
}
