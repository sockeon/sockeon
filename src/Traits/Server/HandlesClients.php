<?php

namespace Sockeon\Sockeon\Traits\Server;

use Sockeon\Sockeon\Core\Config;
use Sockeon\Sockeon\Core\ConnectionPool;
use Sockeon\Sockeon\Core\AsyncTaskQueue;
use Sockeon\Sockeon\Core\PerformanceMonitor;
use Throwable;

trait HandlesClients
{
    /**
     * Connection pool for resource optimization
     * @var ConnectionPool
     */
    protected ConnectionPool $connectionPool;

    /**
     * Async task queue for heavy operations
     * @var AsyncTaskQueue
     */
    protected AsyncTaskQueue $taskQueue;

    /**
     * Performance monitoring
     * @var PerformanceMonitor
     */
    protected PerformanceMonitor $performanceMonitor;

    public function bootstrapEngineRuntime(): void
    {
        $this->connectionPool = new ConnectionPool();
        $this->taskQueue = new AsyncTaskQueue();
        $this->performanceMonitor = new PerformanceMonitor();
        $this->registerTaskProcessors();
        $this->startTime = microtime(true);
        $this->publisher->start();
    }

    public function runEngineLoopHooks(): void
    {
        static $lastQueueCheck = 0.0;
        static $lastBufferCleanup = 0.0;
        static $lastTaskProcessing = 0.0;
        static $lastMonitorUpdate = 0.0;

        $now = microtime(true);

        if (($now - $lastTaskProcessing) > 0.05) {
            $this->taskQueue->processTasks();
            $lastTaskProcessing = $now;
        }

        if (($now - $lastMonitorUpdate) > 1.0) {
            $this->updatePerformanceMetrics();
            $lastMonitorUpdate = $now;
        }

        if (($now - $lastQueueCheck) > 0.1) {
            $this->processQueue(Config::getQueueFile());
            $lastQueueCheck = $now;
        }

        if (($now - $lastBufferCleanup) > 30) {
            $this->cleanupExpiredBuffers();
            $this->cleanupDeadConnections();
            $this->connectionPool->cleanup();
            $lastBufferCleanup = $now;

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
    }

    /**
     * Active connection count per IP address
     * @var array<string, int>
     */
    protected array $connectionsPerIp = [];

    /**
     * Client IP addresses keyed by client ID
     * @var array<string, string>
     */
    protected array $clientIps = [];

    /**
     * @param resource $client
     */
    public function acceptClientConnection($client): void
    {
        $resourceId = (int) $client;

        if ($this->getClientCount() >= $this->getMaxConnections()) {
            @fclose($client);
            $this->logger->warning('[Sockeon Connection] Connection limit reached, rejecting client', [
                'max_connections' => $this->getMaxConnections(),
            ]);

            return;
        }

        $clientIp = $this->getPeerIpFromResource($client);
        if ($clientIp !== null && !$this->isConnectionAllowed($clientIp)) {
            @fclose($client);
            $this->logger->warning('[Sockeon Connection] Connection rate limit exceeded, rejecting client', [
                'ip' => $clientIp,
            ]);

            return;
        }

        $clientId = $this->generateClientId();
        $this->resourceToClientId[$resourceId] = $clientId;

        if ($clientIp !== null) {
            $this->clientIps[$clientId] = $clientIp;
            $this->connectionsPerIp[$clientIp] = ($this->connectionsPerIp[$clientIp] ?? 0) + 1;
        }

        $pooledConnection = $this->connectionPool->acquireConnection('unknown', $clientId, $client);
        $reuseCount = isset($pooledConnection['reuse_count']) && is_int($pooledConnection['reuse_count']) ? $pooledConnection['reuse_count'] : 0;
        if ($reuseCount > 0) {
            $this->logger->debug("[Sockeon Connection] Reusing pooled connection for client: $clientId (reused $reuseCount times)");
        }

        $this->clients[$clientId] = $client;
        $this->clientTypes[$clientId] = 'unknown';
        $this->namespaceManager->joinNamespace($clientId);
        $this->logger->debug("[Sockeon Connection] Client connected: $clientId");
        $this->performanceMonitor->recordRequest('connection');
    }

    public function registerSwooleClient(string $clientId, int $fd, string $type = 'ws', ?string $clientIp = null): void
    {
        $this->resourceToClientId[$fd] = $clientId;

        if ($clientIp !== null) {
            $this->clientIps[$clientId] = $clientIp;
            $this->connectionsPerIp[$clientIp] = ($this->connectionsPerIp[$clientIp] ?? 0) + 1;
        }

        $this->clients[$clientId] = $fd;
        $this->clientTypes[$clientId] = $type;
        $this->namespaceManager->joinNamespace($clientId);
        $this->performanceMonitor->recordRequest('connection');
    }

    /**
     * @var array<string, true>
     */
    protected array $finalizingClients = [];

    public function finalizeSwooleClientDisconnect(string $clientId): void
    {
        if (!isset($this->clients[$clientId]) || isset($this->finalizingClients[$clientId])) {
            return;
        }

        $this->finalizingClients[$clientId] = true;

        try {
            if (($this->clientTypes[$clientId] ?? null) === 'ws') {
                try {
                    $this->router->dispatchSpecialEvent($clientId, 'disconnect');
                } catch (Throwable $e) {
                    // Ignore disconnect event errors
                }
            }

            $client = $this->clients[$clientId];
            $resourceId = is_int($client) ? $client : null;

            unset($this->clients[$clientId], $this->clientTypes[$clientId]);
            unset($this->clientBuffers[$clientId], $this->clientBufferTimestamps[$clientId]);

            if (isset($this->clientData[$clientId])) {
                unset($this->clientData[$clientId]);
            }

            if ($resourceId !== null) {
                unset($this->resourceToClientId[$resourceId]);
            }

            $this->decrementConnectionCountForClient($clientId);
            $this->namespaceManager->leaveNamespace($clientId);
            $this->engine->forgetClient($clientId);

            $this->logger->debug("[Sockeon Connection] Client disconnected: $clientId");
        } catch (Throwable $e) {
            $this->logger->exception($e, ['context' => 'Swoole client disconnection', 'clientId' => $clientId]);
        } finally {
            unset($this->finalizingClients[$clientId]);
        }
    }

    public function isConnectionAllowedForIp(string $clientIp): bool
    {
        return $this->isConnectionAllowed($clientIp);
    }

    /**
     * Client buffers for incomplete requests
     * @var array<string, string>
     */
    protected array $clientBuffers = [];

    /**
     * Client buffer timestamps to handle timeouts
     * @var array<string, float>
     */
    protected array $clientBufferTimestamps = [];

    /**
     * Maximum buffer size per client (10MB to handle large HTTP requests)
     * @var int
     */
    protected int $maxBufferSize = 10485760; // 10MB

    /**
     * @param resource $client
     */
    public function processReadableClient($client): void
    {
        $clientId = $this->getClientIdFromResource($client);

        if ($clientId === null) {
            @fclose($client);

            return;
        }

        try {
            if (!is_resource($client)) {
                $this->disconnectClient($clientId);

                return;
            }

            if (@feof($client)) {
                $this->disconnectClient($clientId);

                return;
            }

            $data = @fread($client, 32768);

            if ($data === '' || $data === false) {
                if (@feof($client)) {
                    $this->disconnectClient($clientId);
                }

                return;
            }

            if (($this->clientTypes[$clientId] ?? 'unknown') === 'ws') {
                $this->handleHttpWs($clientId, $client, $data);
            } else {
                if (!isset($this->clientBuffers[$clientId])) {
                    $this->clientBuffers[$clientId] = '';
                    $this->clientBufferTimestamps[$clientId] = microtime(true);
                }

                $currentBufferSize = strlen($this->clientBuffers[$clientId]);
                $newDataSize = strlen($data);
                if ($currentBufferSize + $newDataSize > $this->maxBufferSize) {
                    $this->logger->warning("Client buffer overflow detected for client: $clientId", [
                        'current_size' => $currentBufferSize,
                        'new_data_size' => $newDataSize,
                        'max_buffer_size' => $this->maxBufferSize,
                    ]);
                    $this->disconnectClient($clientId);

                    return;
                }

                $this->clientBuffers[$clientId] .= $data;

                if ($this->isCompleteHttpRequest($this->clientBuffers[$clientId])) {
                    $this->handleHttpWs($clientId, $client, $this->clientBuffers[$clientId]);
                    unset($this->clientBuffers[$clientId], $this->clientBufferTimestamps[$clientId]);
                } elseif (microtime(true) - $this->clientBufferTimestamps[$clientId] > 15) {
                    $this->logger->warning("Client buffer timeout for client: $clientId");
                    $this->disconnectClient($clientId);
                }
            }
        } catch (Throwable $e) {
            $this->logger->exception($e, ['clientId' => $clientId, 'context' => 'handleClientData']);
            $this->disconnectClient($clientId);
        }
    }

    public function forgetClient(string $clientId): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $client = $this->clients[$clientId];
        $resourceId = is_resource($client) ? (int) $client : null;

        unset($this->clients[$clientId], $this->clientTypes[$clientId]);
        unset($this->clientBuffers[$clientId], $this->clientBufferTimestamps[$clientId]);

        if (isset($this->clientData[$clientId])) {
            unset($this->clientData[$clientId]);
        }

        if ($resourceId !== null) {
            unset($this->resourceToClientId[$resourceId]);
        }

        $this->decrementConnectionCountForClient($clientId);

        $this->namespaceManager->leaveNamespace($clientId);
    }

    /**
     * Check if we have received a complete HTTP request
     *
     * @param string $data The buffered request data
     * @return bool True if the request is complete
     */
    protected function isCompleteHttpRequest(string $data): bool
    {
        if (!str_contains($data, "\r\n\r\n")) {
            return false;
        }

        $headerEndPos = strpos($data, "\r\n\r\n");
        if ($headerEndPos === false) {
            return false;
        }

        $headerSection = substr($data, 0, $headerEndPos);
        $bodySection = substr($data, $headerEndPos + 4);

        $contentLength = 0;
        $transferEncoding = '';
        $lines = explode("\r\n", $headerSection);

        foreach ($lines as $line) {
            if (stripos($line, 'Content-Length:') === 0) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $contentLength = (int) trim($parts[1]);
                }
            } elseif (stripos($line, 'Transfer-Encoding:') === 0) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $transferEncoding = strtolower(trim($parts[1]));
                }
            }
        }

        if ($transferEncoding === 'chunked') {
            return $this->isCompleteChunkedRequest($bodySection);
        }

        if ($contentLength === 0) {
            return true;
        }

        return strlen($bodySection) >= $contentLength;
    }

    /**
     * Check if a chunked request is complete
     *
     * @param string $body The request body
     * @return bool True if the chunked request is complete
     */
    protected function isCompleteChunkedRequest(string $body): bool
    {
        return str_ends_with($body, "0\r\n\r\n");
    }

    /**
     * Clean up expired client buffers
     *
     * @return void
     */
    protected function cleanupExpiredBuffers(): void
    {
        $currentTime = microtime(true);
        $expiredClients = [];

        foreach ($this->clientBufferTimestamps as $clientId => $timestamp) {
            if ($currentTime - $timestamp > 15) {
                $expiredClients[] = $clientId;
            }
        }

        // Clean up expired buffers in batch to reduce fragmentation
        foreach ($expiredClients as $clientId) {
            $this->logger->warning("Cleaning up expired buffer for client: $clientId");

            // Clear buffer memory
            unset($this->clientBuffers[$clientId], $this->clientBufferTimestamps[$clientId]);

            $this->disconnectClient($clientId);
        }
    }

    /**
     * Clean up dead connections that are no longer valid resources
     *
     * @return void
     */
    protected function cleanupDeadConnections(): void
    {
        $deadConnections = [];

        foreach ($this->clients as $clientId => $client) {
            if (!is_resource($client)) {
                $deadConnections[] = $clientId;
                continue;
            }

            // Connection is dead if not a valid resource or end-of-stream
            if (@feof($client)) {
                $deadConnections[] = $clientId;
            }
        }

        foreach ($deadConnections as $clientId) {
            $this->logger->debug("Cleaning up dead connection: $clientId");
            $this->disconnectClient($clientId);
        }

        if (!empty($deadConnections)) {
            $this->logger->info("[Sockeon Cleanup] Cleaned up " . count($deadConnections) . " dead connection(s). Active connections: " . count($this->clients));
        }
    }

    public function disconnectClient(string $clientId): void
    {
        try {
            if (isset($this->clients[$clientId])) {
                $client = $this->clients[$clientId];
                $isResource = is_resource($client);
                $resourceId = $isResource ? (int) $client : (is_int($client) ? $client : null);

                if (($this->clientTypes[$clientId] ?? null) === 'ws' && $isResource) {
                    try {
                        $this->router->dispatchSpecialEvent($clientId, 'disconnect');
                    } catch (Throwable $e) {
                        // Ignore disconnect event errors
                    }
                }

                if ($isResource) {
                    if (!@feof($client)) {
                        $this->connectionPool->releaseConnection($clientId);
                    } else {
                        @fclose($client);
                    }
                } elseif (is_int($client)) {
                    $this->engine->closeConnection($clientId, $client);
                    if (!isset($this->finalizingClients[$clientId])) {
                        $this->finalizeSwooleClientDisconnect($clientId);
                    }

                    return;
                }

                unset($this->clients[$clientId], $this->clientTypes[$clientId]);

                if (isset($this->clientData[$clientId])) {
                    unset($this->clientData[$clientId]);
                }

                unset($this->clientBuffers[$clientId], $this->clientBufferTimestamps[$clientId]);

                if ($resourceId !== null) {
                    unset($this->resourceToClientId[$resourceId]);
                }

                $this->decrementConnectionCountForClient($clientId);
                $this->namespaceManager->leaveNamespace($clientId);
                $this->engine->forgetClient($clientId);

                $this->logger->debug("[Sockeon Connection] Client disconnected: $clientId");
            }
        } catch (Throwable $e) {
            $this->logger->exception($e, ['context' => 'Client disconnection', 'clientId' => $clientId]);
        }
    }

    public function setClientData(string $clientId, string $key, mixed $value): void
    {
        $this->clientData[$clientId][$key] = $value;
    }

    public function getClientData(string $clientId, ?string $key = null): mixed
    {
        if (!isset($this->clientData[$clientId])) {
            return null;
        }

        return $key === null ? $this->clientData[$clientId] : ($this->clientData[$clientId][$key] ?? null);
    }

    /**
     * @param resource $resource
     */
    protected function getPeerIpFromResource($resource): ?string
    {
        $peerName = stream_socket_get_name($resource, true);
        if ($peerName === false) {
            return null;
        }

        $parts = explode(':', $peerName);

        return $parts[0] !== '' ? $parts[0] : null;
    }

    protected function isConnectionAllowed(string $clientIp): bool
    {
        $rateLimitConfig = $this->rateLimitConfig;
        if ($rateLimitConfig === null) {
            return true;
        }

        if ($rateLimitConfig->isWhitelisted($clientIp)) {
            return true;
        }

        if ($this->getClientCount() >= $rateLimitConfig->getMaxGlobalConnections()) {
            return false;
        }

        $ipConnections = $this->connectionsPerIp[$clientIp] ?? 0;

        return $ipConnections < $rateLimitConfig->getMaxConnectionsPerIp();
    }

    protected function decrementConnectionCountForClient(string $clientId): void
    {
        if (!isset($this->clientIps[$clientId])) {
            return;
        }

        $clientIp = $this->clientIps[$clientId];
        unset($this->clientIps[$clientId]);

        if (!isset($this->connectionsPerIp[$clientIp])) {
            return;
        }

        $this->connectionsPerIp[$clientIp]--;
        if ($this->connectionsPerIp[$clientIp] <= 0) {
            unset($this->connectionsPerIp[$clientIp]);
        }
    }

    /**
     * Get the IP address of a client
     *
     * @param string $clientId The client ID
     * @return string|null The client IP address or null if not found
     */
    public function getClientIpAddress(string $clientId): ?string
    {
        if (!isset($this->clients[$clientId]) || !is_resource($this->clients[$clientId])) {
            return null;
        }

        $peerName = stream_socket_get_name($this->clients[$clientId], true);
        if ($peerName === false) {
            return null;
        }

        // Extract IP from the peer name (format: "ip:port")
        $parts = explode(':', $peerName);
        return $parts[0];
    }

    /**
     * Register async task processors
     *
     * @return void
     */
    protected function registerTaskProcessors(): void
    {
        // Database operations
        $this->taskQueue->registerProcessor('db_write', function (array $data, array $task) {
            // Queue database writes to avoid blocking main thread
            $this->logger->debug("[Async Task] Processing DB write", ['data' => $data]);
            // Implement actual database write here
            return true;
        });

        // File operations
        $this->taskQueue->registerProcessor('file_write', function (array $data, array $task) {
            try {
                if (isset($data['path']) && is_string($data['path']) && isset($data['content'])) {
                    file_put_contents($data['path'], $data['content']);
                    return true;
                }
            } catch (Throwable $e) {
                $this->logger->exception($e, ['context' => 'Async file write']);
            }
            return false;
        });

        // Log processing
        $this->taskQueue->registerProcessor('log_process', function (array $data, array $task) {
            // Process logs asynchronously
            $this->logger->debug("[Async Task] Processing log", ['data' => $data]);
            return true;
        });

        // External API calls
        $this->taskQueue->registerProcessor('api_call', function (array $data, array $task) {
            try {
                if (isset($data['url']) && is_string($data['url'])) {
                    // Make external API calls without blocking
                    $method = isset($data['method']) && is_string($data['method']) ? $data['method'] : 'GET';
                    $context = stream_context_create([
                        'http' => [
                            'timeout' => 5,
                            'method' => $method,
                        ],
                    ]);

                    $result = @file_get_contents($data['url'], false, $context);
                    return $result !== false;
                }
            } catch (Throwable $e) {
                $this->logger->exception($e, ['context' => 'Async API call']);
            }
            return false;
        });
    }

    /**
     * Update performance metrics
     *
     * @return void
     */
    protected function updatePerformanceMetrics(): void
    {
        try {
            // Connection statistics
            $connectionStats = [
                'active_connections' => count($this->clients),
                'total_accepted' => count($this->clients), // This should be tracked separately
                'total_closed' => 0, // This should be tracked separately
            ];

            $this->performanceMonitor->updateConnectionStats($connectionStats);

            // Update with connection pool and task queue stats
            $this->performanceMonitor->collectSystemMetrics();
        } catch (Throwable $e) {
            $this->logger->exception($e, ['context' => 'Performance metrics update']);
        }
    }

    /**
     * Queue an async task
     *
     * @param string $type Task type
     * @param array<string, mixed> $data Task data
     * @param int $priority Priority level
     * @return void
     */
    public function queueAsyncTask(string $type, array $data, int $priority = 0): void
    {
        $this->taskQueue->queueTask($type, $data, $priority);
    }

    /**
     * Get comprehensive server statistics
     *
     * @return array<string, mixed>
     */
    public function getServerStats(): array
    {
        return [
            'performance' => $this->performanceMonitor->getMetrics(),
            'connection_pool' => $this->connectionPool->getStats(),
            'task_queue' => $this->taskQueue->getStats(),
            'server_info' => [
                'uptime' => $this->performanceMonitor->getFormattedUptime(),
                'active_clients' => count($this->clients),
                'client_types' => array_count_values($this->clientTypes),
                'pending_tasks' => $this->taskQueue->getPendingCount(),
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ],
        ];
    }

    /**
     * Record request for performance monitoring
     *
     * @param string $type Request type (http/websocket)
     * @param float $responseTime Response time in milliseconds
     * @return void
     */
    public function recordRequestMetric(string $type, float $responseTime = 0): void
    {
        $this->performanceMonitor->recordRequest($type, $responseTime);
    }

    /**
     * Record error for monitoring
     *
     * @param string $type Error type (connection/request)
     * @return void
     */
    public function recordErrorMetric(string $type): void
    {
        $this->performanceMonitor->recordError($type);
    }
}
