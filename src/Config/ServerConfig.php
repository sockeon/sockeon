<?php

namespace Sockeon\Sockeon\Config;

use Sockeon\Sockeon\Contracts\LoggerInterface;

class ServerConfig
{
    /**
     * The host the server will bind to.
     */
    protected string $host = '0.0.0.0';

    /**
     * The port the server will listen on.
     */
    protected int $port = 6001;

    /**
     * Enable or disable debug mode.
     */
    protected bool $debug = false;

    /**
     * CORS configuration
     *
     * @var CorsConfig
     */
    protected CorsConfig $corsConfig;

    /**
     * Optional custom logger. If null, a default logger will be used.
     */
    protected ?LoggerInterface $logger = null;

    /**
     * Optional path to queue file.
     */
    protected ?string $queueFile = null;

    /**
     * Optional authentication key for securing connections.
     */
    protected ?string $authKey = null;

    /**
     * Rate limiting configuration. If null, rate limiting is disabled.
     */
    protected ?RateLimitConfig $rateLimitConfig = null;

    /**
     * Connection survivability limits (hard caps, always enforced).
     */
    protected SurvivabilityConfig $survivabilityConfig;

    /**
     * Runtime engine: stream_select (default) or swoole.
     */
    protected string $engine = 'stream_select';

    protected SwooleEngineConfig $swooleEngineConfig;

    /**
     * Trust proxy settings for reverse proxy/load balancer support.
     * Can be:
     * - true: Trust all proxies
     * - false: Don't trust any proxies (default)
     * - array: List of trusted proxy IPs/CIDR ranges
     *
     * @var bool|array<int, string>
     */
    protected bool|array $trustProxy = false;

    /**
     * Custom proxy header names for X-Forwarded-* headers.
     * Useful when using non-standard proxy headers.
     *
     * @var array<string, string>|null
     */
    protected ?array $proxyHeaders = null;

    /**
     * Health check endpoint path. If set, enables health check endpoint.
     * Default: null (disabled)
     *
     * @var string|null
     */
    protected ?string $healthCheckPath = null;

    /**
     * Maximum message size in bytes. If set, limits the size of incoming messages.
     * Default: 65536 (64KB)
     *
     * @var int
     */
    protected int $maxMessageSize = 65536; // 64KB

    /**
     * Register system controllers (e.g., room management).
     * Set to false if you want to implement custom room handling.
     * Default: true
     *
     * @var bool
     */
    protected bool $registerSystemControllers = true;

    /**
     * Create a new server configuration instance
     *
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        if (isset($config['host']) && is_string($config['host'])) {
            $this->host = $config['host'];
        }

        if (isset($config['port']) && is_int($config['port'])) {
            $this->port = $config['port'];
        } elseif (isset($config['port']) && is_numeric($config['port'])) {
            $this->port = (int) $config['port'];
        }

        if (isset($config['debug'])) {
            if (is_bool($config['debug'])) {
                $this->debug = $config['debug'];
            } else {
                $this->debug = !empty($config['debug']);
            }
        }

        if (isset($config['max_message_size']) && is_int($config['max_message_size'])) {
            $this->maxMessageSize = $config['max_message_size'];
        }

        if (isset($config['register_system_controllers'])) {
            $this->registerSystemControllers = (bool) $config['register_system_controllers'];
        }

        // Initialize CORS config
        if (isset($config['cors']) && $config['cors'] instanceof CorsConfig) {
            $this->corsConfig = $config['cors'];
        } elseif (isset($config['cors']) && is_array($config['cors'])) {
            /** @var array<string, mixed> $corsConfig */
            $corsConfig = $config['cors'];
            $this->corsConfig = new CorsConfig($corsConfig);
        } else {
            $this->corsConfig = new CorsConfig();
        }

        if (isset($config['logger']) && $config['logger'] instanceof LoggerInterface) {
            $this->logger = $config['logger'];
        }

        if (isset($config['queue_file']) && is_string($config['queue_file'])) {
            $this->queueFile = $config['queue_file'];
        }

        if (isset($config['auth_key']) && is_string($config['auth_key'])) {
            $this->authKey = $config['auth_key'];
        }

        // Initialize rate limit config
        if (isset($config['rate_limit']) && $config['rate_limit'] instanceof RateLimitConfig) {
            $this->rateLimitConfig = $config['rate_limit'];
        } elseif (isset($config['rate_limit']) && is_array($config['rate_limit'])) {
            /** @var array<string, mixed> $rateLimitConfig */
            $rateLimitConfig = $config['rate_limit'];
            $this->rateLimitConfig = new RateLimitConfig($rateLimitConfig);
        }

        if (isset($config['survivability']) && $config['survivability'] instanceof SurvivabilityConfig) {
            $this->survivabilityConfig = $config['survivability'];
        } elseif (isset($config['survivability']) && is_array($config['survivability'])) {
            /** @var array<string, mixed> $survivabilityConfig */
            $survivabilityConfig = $config['survivability'];
            $this->survivabilityConfig = new SurvivabilityConfig($survivabilityConfig);
        } else {
            $this->survivabilityConfig = new SurvivabilityConfig();
        }

        if (isset($config['engine']) && is_string($config['engine'])) {
            $this->engine = $config['engine'];
        }

        if (isset($config['swoole']) && $config['swoole'] instanceof SwooleEngineConfig) {
            $this->swooleEngineConfig = $config['swoole'];
        } elseif (isset($config['swoole']) && is_array($config['swoole'])) {
            /** @var array<string, mixed> $swooleConfig */
            $swooleConfig = $config['swoole'];
            $this->swooleEngineConfig = new SwooleEngineConfig($swooleConfig);
        } else {
            $this->swooleEngineConfig = new SwooleEngineConfig();
        }

        // Initialize trust proxy settings
        if (isset($config['trust_proxy'])) {
            if (is_bool($config['trust_proxy'])) {
                $this->trustProxy = $config['trust_proxy'];
            } elseif (is_array($config['trust_proxy'])) {
                /** @var array<int, string> $trustProxyArray */
                $trustProxyArray = $config['trust_proxy'];
                $this->trustProxy = $trustProxyArray;
            }
        }

        // Initialize custom proxy headers
        if (isset($config['proxy_headers']) && is_array($config['proxy_headers'])) {
            /** @var array<string, string> $proxyHeadersArray */
            $proxyHeadersArray = $config['proxy_headers'];
            $this->proxyHeaders = $proxyHeadersArray;
        }

        // Initialize health check path
        if (isset($config['health_check_path']) && is_string($config['health_check_path'])) {
            $this->healthCheckPath = $config['health_check_path'];
        }
    }

    /**
     * Get the host
     *
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Set the host
     *
     * @param string $host
     * @return void
     */
    public function setHost(string $host): void
    {
        $this->host = $host;
    }

    /**
     * Get the port
     *
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Set the port
     *
     * @param int $port
     * @return void
     */
    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Set debug mode
     *
     * @param bool $debug
     * @return void
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
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
     * Set the maximum message size
     *
     * @param int $maxMessageSize
     * @return void
     */
    public function setMaxMessageSize(int $maxMessageSize): void
    {
        $this->maxMessageSize = $maxMessageSize;
    }
    /**
     * Get CORS configuration
     *
     * @return CorsConfig
     */
    public function getCorsConfig(): CorsConfig
    {
        return $this->corsConfig;
    }

    /**
     * Set CORS configuration
     *
     * @param CorsConfig $corsConfig
     * @return void
     */
    public function setCorsConfig(CorsConfig $corsConfig): void
    {
        $this->corsConfig = $corsConfig;
    }

    /**
     * Get the logger
     *
     * @return LoggerInterface|null
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Set the logger
     *
     * @param LoggerInterface|null $logger
     * @return void
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Get the queue file path
     *
     * @return string|null
     */
    public function getQueueFile(): ?string
    {
        return $this->queueFile;
    }

    /**
     * Set the queue file path
     *
     * @param string|null $queueFile
     * @return void
     */
    public function setQueueFile(?string $queueFile): void
    {
        $this->queueFile = $queueFile;
    }

    /**
     * Get the authentication key
     *
     * @return string|null
     */
    public function getAuthKey(): ?string
    {
        return $this->authKey;
    }

    /**
     * Set the authentication key
     *
     * @param string|null $authKey
     * @return void
     */
    public function setAuthKey(?string $authKey): void
    {
        $this->authKey = $authKey;
    }

    /**
     * Get rate limit configuration
     *
     * @return RateLimitConfig|null
     */
    public function getRateLimitConfig(): ?RateLimitConfig
    {
        return $this->rateLimitConfig;
    }

    /**
     * Set rate limit configuration
     *
     * @param RateLimitConfig|null $rateLimitConfig
     * @return void
     */
    public function setRateLimitConfig(?RateLimitConfig $rateLimitConfig): void
    {
        $this->rateLimitConfig = $rateLimitConfig;
    }

    /**
     * Get trust proxy settings
     *
     * @return bool|array<int, string>
     */
    public function getTrustProxy(): bool|array
    {
        return $this->trustProxy;
    }

    /**
     * Set trust proxy settings
     *
     * @param bool|array<int, string> $trustProxy
     * @return void
     */
    public function setTrustProxy(bool|array $trustProxy): void
    {
        $this->trustProxy = $trustProxy;
    }

    /**
     * Get custom proxy headers
     *
     * @return array<string, string>|null
     */
    public function getProxyHeaders(): ?array
    {
        return $this->proxyHeaders;
    }

    /**
     * Set custom proxy headers
     *
     * @param array<string, string>|null $proxyHeaders
     * @return void
     */
    public function setProxyHeaders(?array $proxyHeaders): void
    {
        $this->proxyHeaders = $proxyHeaders;
    }

    /**
     * Get health check endpoint path
     *
     * @return string|null
     */
    public function getHealthCheckPath(): ?string
    {
        return $this->healthCheckPath;
    }

    /**
     * Set health check endpoint path
     *
     * @param string|null $healthCheckPath
     * @return void
     */
    public function setHealthCheckPath(?string $healthCheckPath): void
    {
        $this->healthCheckPath = $healthCheckPath;
    }

    /**
     * Check if system controllers should be registered
     *
     * @return bool
     */
    public function shouldRegisterSystemControllers(): bool
    {
        return $this->registerSystemControllers;
    }

    /**
     * Set whether to register system controllers
     *
     * @param bool $register
     * @return void
     */
    public function setRegisterSystemControllers(bool $register): void
    {
        $this->registerSystemControllers = $register;
    }

    public function getSurvivabilityConfig(): SurvivabilityConfig
    {
        return $this->survivabilityConfig;
    }

    public function setSurvivabilityConfig(SurvivabilityConfig $survivabilityConfig): void
    {
        $this->survivabilityConfig = $survivabilityConfig;
    }

    public function getEngine(): string
    {
        return $this->engine;
    }

    public function setEngine(string $engine): void
    {
        $this->engine = $engine;
    }

    public function getSwooleEngineConfig(): SwooleEngineConfig
    {
        return $this->swooleEngineConfig;
    }

    public function setSwooleEngineConfig(SwooleEngineConfig $swooleEngineConfig): void
    {
        $this->swooleEngineConfig = $swooleEngineConfig;
    }
}
