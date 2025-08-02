<?php
/**
 * Rate Limiting Configuration Class
 * 
 * Handles rate limiting configurations for HTTP requests, WebSocket messages,
 * and connection limits to protect against abuse and ensure fair usage
 * 
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Config;

class RateLimitConfig
{
    /**
     * Enable or disable rate limiting
     * @var bool
     */
    protected bool $enabled = false;

    /**
     * Maximum HTTP requests per IP address per time window
     * @var int
     */
    protected int $maxHttpRequestsPerIp = 100;

    /**
     * HTTP rate limit time window in seconds
     * @var int
     */
    protected int $httpTimeWindow = 60;

    /**
     * Maximum WebSocket messages per client per time window
     * @var int
     */
    protected int $maxWebSocketMessagesPerClient = 200;

    /**
     * WebSocket rate limit time window in seconds
     * @var int
     */
    protected int $webSocketTimeWindow = 60;

    /**
     * Maximum connections per IP address per time window
     * @var int
     */
    protected int $maxConnectionsPerIp = 50;

    /**
     * Connection rate limit time window in seconds
     * @var int
     */
    protected int $connectionTimeWindow = 60;

    /**
     * Maximum total connections across all IPs
     * @var int
     */
    protected int $maxGlobalConnections = 10000;

    /**
     * Additional requests allowed for bursts
     * @var int
     */
    protected int $burstAllowance = 10;

    /**
     * Cleanup interval for expired entries (seconds)
     * @var int
     */
    protected int $cleanupInterval = 300;

    /**
     * Array of IP addresses to bypass rate limiting
     * @var array<int, string>
     */
    protected array $whitelist = [];

    /**
     * Constructor
     * 
     * @param array<string, mixed> $config Configuration array
     */
    public function __construct(array $config = [])
    {
        $this->loadConfiguration($config);
    }

    /**
     * Load configuration from array
     * 
     * @param array<string, mixed> $config Configuration array
     * @return void
     */
    protected function loadConfiguration(array $config): void
    {
        if (isset($config['enabled']) && is_bool($config['enabled'])) {
            $this->enabled = $config['enabled'];
        }

        if (isset($config['maxHttpRequestsPerIp']) && is_int($config['maxHttpRequestsPerIp'])) {
            $this->maxHttpRequestsPerIp = $config['maxHttpRequestsPerIp'];
        }

        if (isset($config['httpTimeWindow']) && is_int($config['httpTimeWindow'])) {
            $this->httpTimeWindow = $config['httpTimeWindow'];
        }

        if (isset($config['maxWebSocketMessagesPerClient']) && is_int($config['maxWebSocketMessagesPerClient'])) {
            $this->maxWebSocketMessagesPerClient = $config['maxWebSocketMessagesPerClient'];
        }

        if (isset($config['webSocketTimeWindow']) && is_int($config['webSocketTimeWindow'])) {
            $this->webSocketTimeWindow = $config['webSocketTimeWindow'];
        }

        if (isset($config['maxConnectionsPerIp']) && is_int($config['maxConnectionsPerIp'])) {
            $this->maxConnectionsPerIp = $config['maxConnectionsPerIp'];
        }

        if (isset($config['connectionTimeWindow']) && is_int($config['connectionTimeWindow'])) {
            $this->connectionTimeWindow = $config['connectionTimeWindow'];
        }

        if (isset($config['maxGlobalConnections']) && is_int($config['maxGlobalConnections'])) {
            $this->maxGlobalConnections = $config['maxGlobalConnections'];
        }

        if (isset($config['burstAllowance']) && is_int($config['burstAllowance'])) {
            $this->burstAllowance = $config['burstAllowance'];
        }

        if (isset($config['cleanupInterval']) && is_int($config['cleanupInterval'])) {
            $this->cleanupInterval = $config['cleanupInterval'];
        }

        if (isset($config['whitelist']) && is_array($config['whitelist'])) {
            $whitelist = [];
            foreach ($config['whitelist'] as $ip) {
                if (is_string($ip)) {
                    $whitelist[] = $ip;
                }
            }
            $this->whitelist = $whitelist;
        }
    }

    /**
     * Check if rate limiting is enabled
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get maximum HTTP requests per IP
     * 
     * @return int
     */
    public function getMaxHttpRequestsPerIp(): int
    {
        return $this->maxHttpRequestsPerIp;
    }

    /**
     * Get HTTP time window
     * 
     * @return int
     */
    public function getHttpTimeWindow(): int
    {
        return $this->httpTimeWindow;
    }

    /**
     * Get maximum WebSocket messages per client
     * 
     * @return int
     */
    public function getMaxWebSocketMessagesPerClient(): int
    {
        return $this->maxWebSocketMessagesPerClient;
    }

    /**
     * Get WebSocket time window
     * 
     * @return int
     */
    public function getWebSocketTimeWindow(): int
    {
        return $this->webSocketTimeWindow;
    }

    /**
     * Get maximum connections per IP
     * 
     * @return int
     */
    public function getMaxConnectionsPerIp(): int
    {
        return $this->maxConnectionsPerIp;
    }

    /**
     * Get connection time window
     * 
     * @return int
     */
    public function getConnectionTimeWindow(): int
    {
        return $this->connectionTimeWindow;
    }

    /**
     * Get maximum global connections
     * 
     * @return int
     */
    public function getMaxGlobalConnections(): int
    {
        return $this->maxGlobalConnections;
    }

    /**
     * Get burst allowance
     * 
     * @return int
     */
    public function getBurstAllowance(): int
    {
        return $this->burstAllowance;
    }

    /**
     * Get cleanup interval
     * 
     * @return int
     */
    public function getCleanupInterval(): int
    {
        return $this->cleanupInterval;
    }

    /**
     * Get whitelist
     * 
     * @return array<int, string>
     */
    public function getWhitelist(): array
    {
        return $this->whitelist;
    }

    /**
     * Check if an IP is whitelisted
     * 
     * @param string $ip IP address to check
     * @return bool
     */
    public function isWhitelisted(string $ip): bool
    {
        foreach ($this->whitelist as $whitelistedIp) {
            if ($this->matchIp($ip, $whitelistedIp)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Match IP address against pattern (supports CIDR notation)
     * 
     * @param string $ip IP address to check
     * @param string $pattern Pattern to match against
     * @return bool
     */
    protected function matchIp(string $ip, string $pattern): bool
    {
        if ($ip === $pattern) {
            return true;
        }

        if (str_contains($pattern, '/')) {
            [$subnet, $mask] = explode('/', $pattern, 2);
            $mask = (int) $mask;
            
            if ($mask < 0 || $mask > 32) {
                return false;
            }
            
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }
            
            $maskLong = -1 << (32 - $mask);
            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        return false;
    }
}
