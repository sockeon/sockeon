<?php
/**
 * Unified Rate Limit Attribute
 * 
 * Attribute for applying rate limiting configuration to both HTTP routes and WebSocket events.
 * Provides fine-grained control over rate limits with flexible naming for different contexts.
 * 
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class RateLimit
{
    /**
     * Constructor
     * 
     * @param int $maxCount Maximum requests/messages per client within the time window
     * @param int $timeWindow Time window in seconds
     * @param int $burstAllowance Additional requests/messages allowed for bursts
     * @param bool $bypassGlobal Whether this rate limit should bypass global rate limiting
     * @param array<int, string> $whitelist Array of IP addresses to bypass this rate limit
     */
    public function __construct(
        protected int $maxCount,
        protected int $timeWindow = 60,
        protected int $burstAllowance = 5,
        protected bool $bypassGlobal = false,
        protected array $whitelist = []
    ) {
        //
    }

    /**
     * Get maximum count allowed (requests for HTTP, messages for WebSocket)
     * 
     * @return int
     */
    public function getMaxCount(): int
    {
        return $this->maxCount;
    }

    /**
     * Get maximum requests (alias for HTTP compatibility)
     * 
     * @return int
     */
    public function getMaxRequests(): int
    {
        return $this->maxCount;
    }

    /**
     * Get maximum messages (alias for WebSocket compatibility)
     * 
     * @return int
     */
    public function getMaxMessages(): int
    {
        return $this->maxCount;
    }

    /**
     * Get time window in seconds
     * 
     * @return int
     */
    public function getTimeWindow(): int
    {
        return $this->timeWindow;
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
     * Check if this rate limit should bypass global rate limiting
     * 
     * @return bool
     */
    public function shouldBypassGlobal(): bool
    {
        return $this->bypassGlobal;
    }

    /**
     * Get whitelist of IP addresses
     * 
     * @return array<int, string>
     */
    public function getWhitelist(): array
    {
        return $this->whitelist;
    }

    /**
     * Check if an IP is whitelisted for this rate limit
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
