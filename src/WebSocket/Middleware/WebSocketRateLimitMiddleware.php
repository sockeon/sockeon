<?php
/**
 * WebSocket Rate Limiting Middleware
 * 
 * Implements rate limiting for WebSocket messages to prevent abuse and ensure fair usage.
 * Tracks messages per client within configurable time windows with support for both 
 * global and event-spe        $message = [
            'event' => 'rate_limit_exceeded',
            'data' => [
                'error' => 'Rate limit exceeded',
                'message' => 'You have exceeded the event-specific rate limit. Please try again later.',
                'original_event' => $event,
                'retry_after' => $rateLimitConfig->getTimeWindow(),
                'limit' => $rateLimitConfig->getMaxMessages(),
                'window' => $rateLimitConfig->getTimeWindow(),
                'type' => $type
            ]
        ];

        $jsonMessage = json_encode($message);
        if ($jsonMessage !== false) {
            $server->sendToClient($clientId, $jsonMessage);
        }its.
 * 
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\WebSocket\Middleware;

use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Contracts\WebSocket\WebsocketMiddleware;
use Sockeon\Sockeon\Core\Attributes\RateLimit;

class WebSocketRateLimitMiddleware implements WebsocketMiddleware
{
    /**
     * Global WebSocket message tracking store per client IP
     * Format: ['ip' => [timestamp1, timestamp2, ...], ...]
     * @var array<string, array<int, float>>
     */
    protected static array $globalMessageStore = [];

    /**
     * Event-specific message tracking store per client IP and event
     * Format: ['event_name' => ['ip' => [timestamp1, timestamp2, ...], ...], ...]
     * @var array<string, array<string, array<int, float>>>
     */
    protected static array $eventMessageStore = [];

    /**
     * Last cleanup timestamp
     * @var float
     */
    protected static float $lastCleanup = 0;

    /**
     * Handle the WebSocket message with rate limiting
     * 
     * @param int $clientId The client ID
     * @param string $event The event name
     * @param array<string, mixed> $data The event data
     * @param callable $next The next middleware handler to call
     * @param Server $server The server instance handling the WebSocket message
     * @return mixed The response from the next middleware or null if rate limited
     */
    public function handle(int $clientId, string $event, array $data, callable $next, Server $server): mixed
    {
        $globalRateLimitConfig = $server->getRateLimitConfig();
        $clientIp = $server->getClientIpAddress($clientId);

        if (!$clientIp) {
            $server->getLogger()->warning("[Sockeon WebSocket Rate Limit] Unable to get client IP address", [
                'client_id' => $clientId,
                'event' => $event
            ]);
            return $next($clientId, $data);
        }

        $eventRateLimit = $this->getEventRateLimit($event, $server);

        $useEventConfig = $eventRateLimit !== null;
        $useGlobalConfig = $globalRateLimitConfig && $globalRateLimitConfig->isEnabled() && (!$useEventConfig || !$eventRateLimit->shouldBypassGlobal());

        $server->getLogger()->debug("[Sockeon WebSocket Rate Limit] Rate limit check", [
            'event' => $event,
            'client_id' => $clientId,
            'client_ip' => $clientIp,
            'has_event_config' => $useEventConfig,
            'use_global_config' => $useGlobalConfig,
            'event_max_messages' => $eventRateLimit ? $eventRateLimit->getMaxMessages() : null,
            'event_bypass_global' => $eventRateLimit ? $eventRateLimit->shouldBypassGlobal() : null
        ]);

        if (!$useEventConfig && !$useGlobalConfig) {
            $server->getLogger()->debug("[Sockeon WebSocket Rate Limit] No rate limiting applied");
            return $next($clientId, $data);
        }

        if ($useEventConfig) {
            $server->getLogger()->debug("[Sockeon WebSocket Rate Limit] Processing event-specific rate limiting");
            if ($eventRateLimit->isWhitelisted($clientIp)) {
                $server->getLogger()->debug("[Sockeon WebSocket Rate Limit] IP is whitelisted for event", [
                    'client_ip' => $clientIp,
                    'event' => $event
                ]);
                if ($eventRateLimit->shouldBypassGlobal()) {
                    $server->getLogger()->debug("[Sockeon WebSocket Rate Limit] Bypassing global rate limiting due to event config");
                    return $next($clientId, $data);
                }
            } else {
                $this->cleanupExpiredEntries($globalRateLimitConfig);

                if ($this->isRateLimitedForEvent($clientIp, $event, $eventRateLimit)) {
                    $server->getLogger()->info("[Sockeon WebSocket Rate Limit] Event rate limit exceeded", [
                        'client_ip' => $clientIp,
                        'client_id' => $clientId,
                        'event' => $event,
                        'limit' => $eventRateLimit->getMaxMessages()
                    ]);
                    $this->sendRateLimitMessage($clientId, $event, $eventRateLimit, $server, 'event-specific');
                    return null;
                }

                $this->recordEventMessage($clientIp, $event);
                $server->getLogger()->debug("[Sockeon WebSocket Rate Limit] Event message recorded", [
                    'client_ip' => $clientIp,
                    'event' => $event
                ]);

                if ($eventRateLimit->shouldBypassGlobal()) {
                    $server->getLogger()->debug("[Sockeon WebSocket Rate Limit] Bypassing global rate limiting after event processing");
                    return $next($clientId, $data);
                }
            }
        }

        if ($useGlobalConfig && $globalRateLimitConfig !== null) {
            $server->getLogger()->debug("[Sockeon WebSocket Rate Limit] Processing global rate limiting");
            if ($globalRateLimitConfig->isWhitelisted($clientIp)) {
                $server->getLogger()->debug("[Sockeon WebSocket Rate Limit] IP is whitelisted globally", [
                    'client_ip' => $clientIp
                ]);
                return $next($clientId, $data);
            }

            $this->cleanupExpiredEntries($globalRateLimitConfig);

            if ($this->isGloballyRateLimited($clientIp, $globalRateLimitConfig)) {
                $server->getLogger()->info("[Sockeon WebSocket Rate Limit] Global rate limit exceeded", [
                    'client_ip' => $clientIp,
                    'client_id' => $clientId,
                    'event' => $event,
                    'limit' => $globalRateLimitConfig->getMaxWebSocketMessagesPerClient(),
                    'burst_allowance' => $globalRateLimitConfig->getBurstAllowance()
                ]);
                $this->sendGlobalRateLimitMessage($clientId, $event, $globalRateLimitConfig, $server);
                return null;
            }

            $this->recordGlobalMessage($clientIp);
            $server->getLogger()->debug("[Sockeon WebSocket Rate Limit] Global message recorded", [
                'client_ip' => $clientIp
            ]);
        }

        return $next($clientId, $data);
    }

    /**
     * Check if the IP is globally rate limited
     * 
     * @param string $ip Client IP address
     * @param \Sockeon\Sockeon\Config\RateLimitConfig $config Rate limit configuration
     * @return bool True if rate limited, false otherwise
     */
    protected function isGloballyRateLimited(string $ip, $config): bool
    {
        $currentTime = microtime(true);
        $timeWindow = $config->getWebSocketTimeWindow();
        $maxMessages = $config->getMaxWebSocketMessagesPerClient();
        $burstAllowance = $config->getBurstAllowance();

        $messages = self::$globalMessageStore[$ip] ?? [];

        $recentMessages = array_filter($messages, function ($timestamp) use ($currentTime, $timeWindow) {
            return ($currentTime - $timestamp) <= $timeWindow;
        });

        return count($recentMessages) >= ($maxMessages + $burstAllowance);
    }

    /**
     * Check if the IP is rate limited for a specific event
     * 
     * @param string $ip Client IP address
     * @param string $event Event name
     * @param RateLimit $rateLimitConfig Event-specific rate limit configuration
     * @return bool True if rate limited, false otherwise
     */
    protected function isRateLimitedForEvent(string $ip, string $event, RateLimit $rateLimitConfig): bool
    {
        $currentTime = microtime(true);
        $timeWindow = $rateLimitConfig->getTimeWindow();
        $maxMessages = $rateLimitConfig->getMaxMessages();
        $burstAllowance = $rateLimitConfig->getBurstAllowance();

        $messages = self::$eventMessageStore[$event][$ip] ?? [];

        $recentMessages = array_filter($messages, function ($timestamp) use ($currentTime, $timeWindow) {
            return ($currentTime - $timestamp) <= $timeWindow;
        });

        return count($recentMessages) >= ($maxMessages + $burstAllowance);
    }

    /**
     * Record a global message for the given IP
     * 
     * @param string $ip Client IP address
     * @return void
     */
    protected function recordGlobalMessage(string $ip): void
    {
        $currentTime = microtime(true);

        if (!isset(self::$globalMessageStore[$ip])) {
            self::$globalMessageStore[$ip] = [];
        }

        self::$globalMessageStore[$ip][] = $currentTime;
    }

    /**
     * Record an event-specific message for the given IP and event
     * 
     * @param string $ip Client IP address
     * @param string $event Event name
     * @return void
     */
    protected function recordEventMessage(string $ip, string $event): void
    {
        $currentTime = microtime(true);

        if (!isset(self::$eventMessageStore[$event])) {
            self::$eventMessageStore[$event] = [];
        }

        if (!isset(self::$eventMessageStore[$event][$ip])) {
            self::$eventMessageStore[$event][$ip] = [];
        }

        self::$eventMessageStore[$event][$ip][] = $currentTime;
    }

    /**
     * Send a rate limit exceeded message to the client
     * 
     * @param int $clientId Client ID
     * @param string $event Event name
     * @param RateLimit $rateLimitConfig Rate limit configuration
     * @param Server $server Server instance
     * @param string $type Type of rate limit
     * @return void
     */
    protected function sendRateLimitMessage(int $clientId, string $event, RateLimit $rateLimitConfig, Server $server, string $type): void
    {
        $message = [
            'event' => 'rate_limit_exceeded',
            'data' => [
                'error' => 'Rate limit exceeded',
                'message' => "You have exceeded the rate limit for event '{$event}'. Please try again later.",
                'original_event' => $event,
                'retry_after' => $rateLimitConfig->getTimeWindow(),
                'limit' => $rateLimitConfig->getMaxMessages(),
                'window' => $rateLimitConfig->getTimeWindow(),
                'type' => $type
            ]
        ];

        $jsonMessage = json_encode($message);
        if ($jsonMessage !== false) {
            $server->sendToClient($clientId, $jsonMessage);
        }
    }

    /**
     * Send a global rate limit exceeded message to the client
     * 
     * @param int $clientId Client ID
     * @param string $event Event name
     * @param \Sockeon\Sockeon\Config\RateLimitConfig $rateLimitConfig Rate limit configuration
     * @param Server $server Server instance
     * @return void
     */
    protected function sendGlobalRateLimitMessage(int $clientId, string $event, $rateLimitConfig, Server $server): void
    {
        $message = [
            'event' => 'rate_limit_exceeded',
            'data' => [
                'error' => 'Rate limit exceeded',
                'message' => 'You have exceeded the global WebSocket message rate limit. Please try again later.',
                'original_event' => $event,
                'retry_after' => $rateLimitConfig->getWebSocketTimeWindow(),
                'limit' => $rateLimitConfig->getMaxWebSocketMessagesPerClient(),
                'window' => $rateLimitConfig->getWebSocketTimeWindow(),
                'type' => 'global'
            ]
        ];

        $jsonMessage = json_encode($message);
        if ($jsonMessage !== false) {
            $server->sendToClient($clientId, $jsonMessage);
        }
    }

    /**
     * Clean up expired entries from the message stores
     * 
     * @param \Sockeon\Sockeon\Config\RateLimitConfig|null $config Rate limit configuration
     * @return void
     */
    protected function cleanupExpiredEntries($config): void
    {
        $currentTime = microtime(true);

        $cleanupInterval = $config ? $config->getCleanupInterval() : 300;

        if (($currentTime - self::$lastCleanup) < $cleanupInterval) {
            return;
        }

        $timeWindow = $config ? $config->getWebSocketTimeWindow() : 60;

        // Clean up global message store
        foreach (self::$globalMessageStore as $ip => $messages) {
            $validMessages = array_filter($messages, function ($timestamp) use ($currentTime, $timeWindow) {
                return ($currentTime - $timestamp) <= $timeWindow;
            });

            if (empty($validMessages)) {
                unset(self::$globalMessageStore[$ip]);
            } else {
                self::$globalMessageStore[$ip] = array_values($validMessages);
            }
        }

        // Clean up event-specific message store
        foreach (self::$eventMessageStore as $event => $eventData) {
            foreach ($eventData as $ip => $messages) {
                $validMessages = array_filter($messages, function ($timestamp) use ($currentTime, $timeWindow) {
                    return ($currentTime - $timestamp) <= $timeWindow;
                });

                if (empty($validMessages)) {
                    unset(self::$eventMessageStore[$event][$ip]);
                } else {
                    self::$eventMessageStore[$event][$ip] = array_values($validMessages);
                }
            }

            if (empty(self::$eventMessageStore[$event])) {
                unset(self::$eventMessageStore[$event]);
            }
        }

        self::$lastCleanup = $currentTime;
    }

    /**
     * Get event-specific rate limit configuration from attributes
     * 
     * @param string $event Event name
     * @param Server $server Server instance
     * @return RateLimit|null Event-specific rate limit configuration or null
     */
    protected function getEventRateLimit(string $event, Server $server): ?RateLimit
    {
        $router = $server->getRouter();

        $wsRoutes = $router->getWebSocketRoutes();

        if (isset($wsRoutes[$event])) {
            [$controller, $methodName, $middlewares, $excludeGlobalMiddlewares] = $wsRoutes[$event];

            $server->getLogger()->debug("[Sockeon WebSocket Rate Limit] Found event for rate limit lookup", [
                'event' => $event,
                'controller' => get_class($controller),
                'method' => $methodName
            ]);

            try {
                $reflection = new \ReflectionMethod($controller, $methodName);
                $attributes = $reflection->getAttributes(RateLimit::class);

                if (!empty($attributes)) {
                    $rateLimit = $attributes[0]->newInstance();
                    $server->getLogger()->debug("[Sockeon WebSocket Rate Limit] RateLimit attribute found", [
                        'max_messages' => $rateLimit->getMaxMessages(),
                        'time_window' => $rateLimit->getTimeWindow(),
                        'burst_allowance' => $rateLimit->getBurstAllowance(),
                        'bypass_global' => $rateLimit->shouldBypassGlobal()
                    ]);
                    return $rateLimit;
                } else {
                    $server->getLogger()->debug("[Sockeon WebSocket Rate Limit] No RateLimit attributes found on event method");
                }
            } catch (\ReflectionException $e) {
                $server->getLogger()->warning("[Sockeon WebSocket Rate Limit] Reflection error while checking for RateLimit attributes", [
                    'error' => $e->getMessage(),
                    'controller' => get_class($controller),
                    'method' => $methodName
                ]);
                // If reflection fails, continue without event-specific limits
            }
        } else {
            $server->getLogger()->debug("[Sockeon WebSocket Rate Limit] Event not found in registered routes", [
                'looking_for' => $event,
                'available_events' => array_keys($wsRoutes)
            ]);
        }

        return null;
    }

    /**
     * Get current rate limiting statistics
     * 
     * @return array<string, mixed> Rate limiting statistics
     */
    public static function getStats(): array
    {
        $globalEntries = count(self::$globalMessageStore);
        $globalMessages = 0;

        foreach (self::$globalMessageStore as $messages) {
            $globalMessages += count($messages);
        }

        $eventEntries = 0;
        $eventMessages = 0;

        foreach (self::$eventMessageStore as $eventData) {
            foreach ($eventData as $messages) {
                $eventEntries++;
                $eventMessages += count($messages);
            }
        }

        return [
            'global_tracking' => [
                'tracked_ips' => $globalEntries,
                'total_messages' => $globalMessages,
                'store_size_kb' => round(strlen(serialize(self::$globalMessageStore)) / 1024, 2),
            ],
            'event_tracking' => [
                'tracked_events' => count(self::$eventMessageStore),
                'tracked_ip_event_combinations' => $eventEntries,
                'total_messages' => $eventMessages,
                'store_size_kb' => round(strlen(serialize(self::$eventMessageStore)) / 1024, 2),
            ],
            'last_cleanup' => self::$lastCleanup
        ];
    }

    /**
     * Clear all rate limiting data (useful for testing)
     * 
     * @return void
     */
    public static function clearStore(): void
    {
        self::$globalMessageStore = [];
        self::$eventMessageStore = [];
        self::$lastCleanup = 0;
    }
}
