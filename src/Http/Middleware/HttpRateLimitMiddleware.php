<?php

/**
 * HTTP Rate Limiting Middleware
 * 
 * Implements rate limiting for HTTP requests to prevent abuse and ensure fair usage.
 * Tracks requests per IP address within configurable time windows.
 * 
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Http\Middleware;

use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Contracts\Http\HttpMiddleware;
use Sockeon\Sockeon\Core\Attributes\RateLimit;
use Sockeon\Sockeon\Http\Request;
use Sockeon\Sockeon\Http\Response;

class HttpRateLimitMiddleware implements HttpMiddleware
{
    /**
     * Request tracking store per IP address
     * Format: ['ip' => [timestamp1, timestamp2, ...], ...]
     * @var array<string, array<int, float>>
     */
    protected static array $requestStore = [];

    /**
     * Route-specific request tracking store per IP address and route
     * Format: ['route_key' => ['ip' => [timestamp1, timestamp2, ...], ...], ...]
     * @var array<string, array<string, array<int, float>>>
     */
    protected static array $routeRequestStore = [];

    /**
     * Last cleanup timestamp
     * @var float
     */
    protected static float $lastCleanup = 0;

    /**
     * Handle the HTTP request with rate limiting
     * 
     * @param Request $request The HTTP request object
     * @param callable $next The next middleware handler to call
     * @param Server $server The server instance handling the HTTP request
     * @return mixed The response from the next middleware or rate limit response
     */
    public function handle(Request $request, callable $next, Server $server): mixed
    {
        $globalRateLimitConfig = $server->getRateLimitConfig();
        $clientIp = $request->getIpAddress(true);

        $routeRateLimit = $this->getRouteRateLimit($request, $server);

        $useRouteConfig = $routeRateLimit !== null;
        $useGlobalConfig = $globalRateLimitConfig && $globalRateLimitConfig->isEnabled() && (!$useRouteConfig || !$routeRateLimit->shouldBypassGlobal());

        $server->getLogger()->debug("[Sockeon Rate Limit] Rate limit check", [
            'route' => $request->getMethod() . ' ' . $request->getPath(),
            'client_ip' => $clientIp,
            'has_route_config' => $useRouteConfig,
            'use_global_config' => $useGlobalConfig,
            'route_max_requests' => $routeRateLimit ? $routeRateLimit->getMaxRequests() : null,
            'route_bypass_global' => $routeRateLimit ? $routeRateLimit->shouldBypassGlobal() : null
        ]);

        if (!$useRouteConfig && !$useGlobalConfig) {
            $server->getLogger()->debug("[Sockeon Rate Limit] No rate limiting applied");
            return $next($request);
        }

        if ($useRouteConfig) {
            $server->getLogger()->debug("[Sockeon Rate Limit] Processing route-specific rate limiting");
            if ($clientIp !== null && $routeRateLimit->isWhitelisted($clientIp)) {
                $server->getLogger()->debug("[Sockeon Rate Limit] IP is whitelisted for route", ['client_ip' => $clientIp]);
                if ($routeRateLimit->shouldBypassGlobal()) {
                    $server->getLogger()->debug("[Sockeon Rate Limit] Bypassing global rate limiting due to route config");
                    return $next($request);
                }
            } else {
                $this->cleanupExpiredEntries($globalRateLimitConfig);

                if ($clientIp !== null && $this->isRateLimitedForRoute($clientIp, $routeRateLimit, $request)) {
                    $server->getLogger()->info("[Sockeon Rate Limit] Route rate limit exceeded", [
                        'client_ip' => $clientIp,
                        'route' => $request->getMethod() . ' ' . $request->getPath(),
                        'limit' => $routeRateLimit->getMaxRequests()
                    ]);
                    return $this->createRouteRateLimitResponse($clientIp, $routeRateLimit);
                }

                if ($clientIp !== null) {
                    $this->recordRouteRequest($clientIp, $request);
                    $server->getLogger()->debug("[Sockeon Rate Limit] Route request recorded", ['client_ip' => $clientIp]);
                }

                if ($routeRateLimit->shouldBypassGlobal()) {
                    $server->getLogger()->debug("[Sockeon Rate Limit] Bypassing global rate limiting after route processing");
                    return $next($request);
                }
            }
        }

        if ($useGlobalConfig && $globalRateLimitConfig !== null) {
            $server->getLogger()->debug("[Sockeon Rate Limit] Processing global rate limiting");
            if ($clientIp !== null && $globalRateLimitConfig->isWhitelisted($clientIp)) {
                $server->getLogger()->debug("[Sockeon Rate Limit] IP is whitelisted globally", ['client_ip' => $clientIp]);
                return $next($request);
            }

            $this->cleanupExpiredEntries($globalRateLimitConfig);

            if ($clientIp !== null && $this->isRateLimited($clientIp, $globalRateLimitConfig)) {
                $server->getLogger()->info("[Sockeon Rate Limit] Global rate limit exceeded", [
                    'client_ip' => $clientIp,
                    'limit' => $globalRateLimitConfig->getMaxHttpRequestsPerIp(),
                    'burst_allowance' => $globalRateLimitConfig->getBurstAllowance()
                ]);
                return $this->createRateLimitResponse($clientIp, $globalRateLimitConfig);
            }

            if ($clientIp !== null) {
                $this->recordRequest($clientIp);
                $server->getLogger()->debug("[Sockeon Rate Limit] Global request recorded", ['client_ip' => $clientIp]);
            }
        }

        return $next($request);
    }

    /**
     * Check if the IP is rate limited
     * 
     * @param string $ip Client IP address
     * @param \Sockeon\Sockeon\Config\RateLimitConfig $config Rate limit configuration
     * @return bool True if rate limited, false otherwise
     */
    protected function isRateLimited(string $ip, $config): bool
    {
        $currentTime = microtime(true);
        $timeWindow = $config->getHttpTimeWindow();
        $maxRequests = $config->getMaxHttpRequestsPerIp();
        $burstAllowance = $config->getBurstAllowance();

        $requests = self::$requestStore[$ip] ?? [];

        $recentRequests = array_filter($requests, function ($timestamp) use ($currentTime, $timeWindow) {
            return ($currentTime - $timestamp) <= $timeWindow;
        });

        return count($recentRequests) >= ($maxRequests + $burstAllowance);
    }

    /**
     * Record a request for the given IP
     * 
     * @param string $ip Client IP address
     * @return void
     */
    protected function recordRequest(string $ip): void
    {
        $currentTime = microtime(true);

        if (!isset(self::$requestStore[$ip])) {
            self::$requestStore[$ip] = [];
        }

        self::$requestStore[$ip][] = $currentTime;
    }

    /**
     * Create rate limit exceeded response
     * 
     * @param string $ip Client IP address
     * @param \Sockeon\Sockeon\Config\RateLimitConfig $config Rate limit configuration
     * @return Response Rate limit response
     */
    protected function createRateLimitResponse(string $ip, $config): Response
    {
        $retryAfter = $config->getHttpTimeWindow();
        $limit = $config->getMaxHttpRequestsPerIp();

        $remaining = 0;

        $resetTime = time() + $retryAfter;

        $headers = [
            'X-RateLimit-Limit' => (string) $limit,
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset' => (string) $resetTime,
            'Retry-After' => (string) $retryAfter
        ];

        $responseData = [
            'error' => 'Rate limit exceeded',
            'message' => 'You have exceeded the rate limit. Please try again later.',
            'retry_after' => $retryAfter,
            'limit' => $limit,
            'window' => $config->getHttpTimeWindow()
        ];

        return Response::json($responseData, 429, $headers);
    }

    /**
     * Clean up expired entries from the request store
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

        $timeWindow = $config ? $config->getHttpTimeWindow() : 60;

        foreach (self::$requestStore as $ip => $requests) {
            $validRequests = array_filter($requests, function ($timestamp) use ($currentTime, $timeWindow) {
                return ($currentTime - $timestamp) <= $timeWindow;
            });

            if (empty($validRequests)) {
                unset(self::$requestStore[$ip]);
            } else {
                self::$requestStore[$ip] = array_values($validRequests);
            }
        }

        foreach (self::$routeRequestStore as $routeKey => $routeData) {
            foreach ($routeData as $ip => $requests) {
                $validRequests = array_filter($requests, function ($timestamp) use ($currentTime, $timeWindow) {
                    return ($currentTime - $timestamp) <= $timeWindow;
                });

                if (empty($validRequests)) {
                    unset(self::$routeRequestStore[$routeKey][$ip]);
                } else {
                    self::$routeRequestStore[$routeKey][$ip] = array_values($validRequests);
                }
            }

            if (empty(self::$routeRequestStore[$routeKey])) {
                unset(self::$routeRequestStore[$routeKey]);
            }
        }

        self::$lastCleanup = $currentTime;
    }

    /**
     * Get current rate limiting statistics
     * 
     * @return array<string, mixed> Rate limiting statistics
     */
    public static function getStats(): array
    {
        $totalEntries = count(self::$requestStore);
        $totalRequests = 0;

        foreach (self::$requestStore as $requests) {
            $totalRequests += count($requests);
        }

        $routeEntries = 0;
        $routeRequests = 0;

        foreach (self::$routeRequestStore as $routeData) {
            foreach ($routeData as $requests) {
                $routeEntries++;
                $routeRequests += count($requests);
            }
        }

        return [
            'global_tracking' => [
                'tracked_ips' => $totalEntries,
                'total_requests' => $totalRequests,
                'store_size_kb' => round(strlen(serialize(self::$requestStore)) / 1024, 2),
            ],
            'route_tracking' => [
                'tracked_routes' => count(self::$routeRequestStore),
                'tracked_ip_route_combinations' => $routeEntries,
                'total_requests' => $routeRequests,
                'store_size_kb' => round(strlen(serialize(self::$routeRequestStore)) / 1024, 2),
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
        self::$requestStore = [];
        self::$routeRequestStore = [];
        self::$lastCleanup = 0;
    }

    /**
     * Get route-specific rate limit configuration from attributes
     * 
     * @param Request $request The HTTP request
     * @param Server $server The server instance
     * @return RateLimit|null Route-specific rate limit configuration or null
     */
    protected function getRouteRateLimit(Request $request, Server $server): ?RateLimit
    {
        $router = $server->getRouter();

        $method = $request->getMethod();
        $path = $request->getPath();

        $routes = $router->getHttpRoutes();
        $routeKey = $method . ' ' . $path;

        if (isset($routes[$routeKey])) {
            $route = $routes[$routeKey];
            $controller = $route[0];
            $methodName = $route[1];

            $server->getLogger()->debug("[Sockeon Rate Limit] Found route for rate limit lookup", [
                'route_key' => $routeKey,
                'controller' => get_class($controller),
                'method' => $methodName
            ]);

            try {
                $reflection = new \ReflectionMethod($controller, $methodName);
                $attributes = $reflection->getAttributes(RateLimit::class);

                if (!empty($attributes)) {
                    $rateLimit = $attributes[0]->newInstance();
                    $server->getLogger()->debug("[Sockeon Rate Limit] RateLimit attribute found", [
                        'max_requests' => $rateLimit->getMaxRequests(),
                        'time_window' => $rateLimit->getTimeWindow(),
                        'burst_allowance' => $rateLimit->getBurstAllowance(),
                        'bypass_global' => $rateLimit->shouldBypassGlobal()
                    ]);
                    return $rateLimit;
                } else {
                    $server->getLogger()->debug("[Sockeon Rate Limit] No RateLimit attributes found on route method");
                }
            } catch (\ReflectionException $e) {
                $server->getLogger()->warning("[Sockeon Rate Limit] Reflection error while checking for RateLimit attributes", [
                    'error' => $e->getMessage(),
                    'controller' => get_class($controller),
                    'method' => $methodName
                ]);
                // If reflection fails, continue without route-specific limits
            }
        } else {
            $server->getLogger()->debug("[Sockeon Rate Limit] Route not found in registered routes", [
                'looking_for' => $routeKey,
                'available_routes' => array_keys($routes)
            ]);
        }

        return null;
    }

    /**
     * Check if the IP is rate limited for a specific route
     * 
     * @param string $ip Client IP address
     * @param RateLimit $rateLimitConfig Route-specific rate limit configuration
     * @param Request $request The HTTP request
     * @return bool True if rate limited, false otherwise
     */
    protected function isRateLimitedForRoute(string $ip, RateLimit $rateLimitConfig, Request $request): bool
    {
        $currentTime = microtime(true);
        $timeWindow = $rateLimitConfig->getTimeWindow();
        $maxRequests = $rateLimitConfig->getMaxRequests();
        $burstAllowance = $rateLimitConfig->getBurstAllowance();

        $routeKey = $this->getRouteKey($request);

        $requests = self::$routeRequestStore[$routeKey][$ip] ?? [];

        $recentRequests = array_filter($requests, function ($timestamp) use ($currentTime, $timeWindow) {
            return ($currentTime - $timestamp) <= $timeWindow;
        });

        return count($recentRequests) >= ($maxRequests + $burstAllowance);
    }

    /**
     * Record a request for route-specific tracking
     * 
     * @param string $ip Client IP address
     * @param Request $request The HTTP request
     * @return void
     */
    protected function recordRouteRequest(string $ip, Request $request): void
    {
        $currentTime = microtime(true);
        $routeKey = $this->getRouteKey($request);

        if (!isset(self::$routeRequestStore[$routeKey])) {
            self::$routeRequestStore[$routeKey] = [];
        }

        if (!isset(self::$routeRequestStore[$routeKey][$ip])) {
            self::$routeRequestStore[$routeKey][$ip] = [];
        }

        self::$routeRequestStore[$routeKey][$ip][] = $currentTime;
    }

    /**
     * Create route-specific rate limit exceeded response
     * 
     * @param string $ip Client IP address
     * @param RateLimit $rateLimitConfig Route-specific rate limit configuration
     * @return Response Rate limit response
     */
    protected function createRouteRateLimitResponse(string $ip, RateLimit $rateLimitConfig): Response
    {
        $retryAfter = $rateLimitConfig->getTimeWindow();
        $limit = $rateLimitConfig->getMaxRequests();

        $remaining = 0;

        $resetTime = time() + $retryAfter;

        $headers = [
            'X-RateLimit-Limit' => (string) $limit,
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset' => (string) $resetTime,
            'X-RateLimit-Type' => 'route-specific',
            'Retry-After' => (string) $retryAfter
        ];

        $responseData = [
            'error' => 'Rate limit exceeded',
            'message' => 'You have exceeded the rate limit for this route. Please try again later.',
            'retry_after' => $retryAfter,
            'limit' => $limit,
            'window' => $rateLimitConfig->getTimeWindow(),
            'type' => 'route-specific'
        ];

        return Response::json($responseData, 429, $headers);
    }

    /**
     * Generate a unique key for route tracking
     * 
     * @param Request $request The HTTP request
     * @return string Route key
     */
    protected function getRouteKey(Request $request): string
    {
        return $request->getMethod() . ' ' . $request->getPath();
    }
}
