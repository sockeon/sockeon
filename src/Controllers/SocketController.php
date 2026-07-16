<?php

/**
 * SocketController abstract class
 *
 * Base class for all socket controllers providing access to core server functionalities
 * Provides methods for emitting events, broadcasting messages, and managing rooms
 *
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Controllers;

use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Contracts\LoggerInterface;
use Sockeon\Sockeon\Contracts\Namespace\NamespaceManagerInterface;
use Sockeon\Sockeon\Core\Router;

abstract class SocketController
{
    /**
     * Instance of the server
     */
    private Server $server;

    /**
     * Sets the server instance for this controller
     *
     * This method is called by the server when registering the controller
     *
     * @param Server $server The server instance to set
     */
    public function setServer(Server $server): void
    {
        $this->server = $server;
    }

    /**
     * Emits an event to a specific client
     *
     * @param string $clientId The ID of the client to send to
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     */
    public function emit(string $clientId, string $event, array $data): void
    {
        $this->server->emit($clientId, $event, $data);
    }

    /**
     * Sends a raw WebSocket payload to a specific client without event framing
     *
     * @param string $clientId The ID of the client to send to
     * @param string $payload Raw message data
     */
    public function sendRaw(string $clientId, string $payload): void
    {
        $this->server->sendRaw($clientId, $payload);
    }

    /**
     * Broadcasts an event to multiple clients
     *
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     * @param string|null $namespace Optional namespace to broadcast within
     * @param string|null $room Optional room to broadcast to
     */
    public function broadcast(string $event, array $data, ?string $namespace = null, ?string $room = null): void
    {
        $this->server->broadcast($event, $data, $namespace, $room);
    }

    /**
     * Broadcasts an event to the given client IDs
     *
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     * @param list<string> $clientIds Client IDs to send to
     */
    public function broadcastTo(string $event, array $data, array $clientIds): void
    {
        foreach ($clientIds as $clientId) {
            $this->emit($clientId, $event, $data);
        }
    }

    /**
     * Broadcasts an event to all connected clients except the given ones
     *
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     * @param list<string> $exceptClientIds Client IDs to exclude
     */
    public function broadcastExcept(string $event, array $data, array $exceptClientIds): void
    {
        foreach ($this->getClientIds() as $clientId) {
            if (!in_array($clientId, $exceptClientIds, true)) {
                $this->emit($clientId, $event, $data);
            }
        }
    }

    /**
     * Broadcasts an event to all clients in a specific namespace
     *
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     * @param string $namespace The namespace to broadcast to
     */
    public function broadcastToNamespace(string $event, array $data, string $namespace): void
    {
        $this->server->broadcast($event, $data, $namespace);
    }

    /**
     * Broadcasts an event to all clients in a specific room
     *
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     * @param string $namespace The namespace containing the room
     * @param string $room The room to broadcast to
     */
    public function broadcastToRoom(string $event, array $data, string $namespace, string $room): void
    {
        $this->server->broadcast($event, $data, $namespace, $room);
    }

    /**
     * Joins a client to a namespace
     *
     * @param string $clientId The client ID to move
     * @param string $namespace The namespace to join
     */
    public function joinNamespace(string $clientId, string $namespace = '/'): void
    {
        $this->server->getNamespaceManager()->joinNamespace($clientId, $namespace);
    }

    /**
     * Removes a client from their current namespace
     *
     * @param string $clientId The client ID to remove
     */
    public function leaveNamespace(string $clientId): void
    {
        $this->server->getNamespaceManager()->leaveNamespace($clientId);
    }

    /**
     * Adds a client to a room
     *
     * @param string $clientId The ID of the client to add
     * @param string $room The room name
     * @param string $namespace The namespace containing the room
     */
    public function joinRoom(string $clientId, string $room, string $namespace = '/'): void
    {
        $this->server->joinRoom($clientId, $room, $namespace);
    }

    /**
     * Removes a client from a room
     *
     * @param string $clientId The ID of the client to remove
     * @param string $room The room name to leave
     * @param string $namespace The namespace containing the room
     */
    public function leaveRoom(string $clientId, string $room, string $namespace = '/'): void
    {
        $this->server->leaveRoom($clientId, $room, $namespace);
    }

    /**
     * Removes a client from all rooms they belong to
     *
     * @param string $clientId The client ID to remove from all rooms
     */
    public function leaveAllRooms(string $clientId): void
    {
        $this->server->getNamespaceManager()->leaveAllRooms($clientId);
    }

    /**
     * Disconnects a client from the server
     *
     * Closes the connection and cleans up all client resources
     *
     * @param string $clientId The ID of the client to disconnect
     */
    public function disconnect(string $clientId): void
    {
        $this->server->disconnectClient($clientId);
    }

    /**
     * Gets all arbitrary data attached to a client connection
     *
     * @param string $clientId The client ID
     * @return array<string, mixed>|null All stored data, or null if none
     */
    public function allData(string $clientId): ?array
    {
        return $this->server->allData($clientId);
    }

    /**
     * Gets a single value from a client's attached data
     *
     * @param string $clientId The client ID
     * @param string $key The data key to retrieve
     * @return mixed The stored value, or null if not found
     */
    public function data(string $clientId, string $key): mixed
    {
        return $this->server->data($clientId, $key);
    }

    /**
     * Stores a value in a client's attached data
     *
     * @param string $clientId The client ID
     * @param string $key The data key to set
     * @param mixed $value The value to store
     */
    public function putData(string $clientId, string $key, mixed $value): void
    {
        $this->server->putData($clientId, $key, $value);
    }

    /**
     * Checks whether a client has a specific data key
     *
     * @param string $clientId The client ID
     * @param string $key The data key to check
     */
    public function hasData(string $clientId, string $key): bool
    {
        return $this->server->hasData($clientId, $key);
    }

    /**
     * Removes data from a client connection
     *
     * When $key is null, all data for the client is removed.
     *
     * @param string $clientId The client ID
     * @param string|null $key Optional key to remove; omit to clear all data
     */
    public function forgetData(string $clientId, ?string $key = null): void
    {
        $this->server->forgetData($clientId, $key);
    }

    /**
     * Gets all clients in a specific namespace
     *
     * @param string $namespace The namespace to query (default: '/')
     * @return array<string, string> Array of client IDs in the namespace
     */
    public function getClientsInNamespace(string $namespace = '/'): array
    {
        return $this->server->getClientsInNamespace($namespace);
    }

    /**
     * Gets the namespace a client belongs to
     *
     * @param string $clientId The client ID to query
     * @return string The namespace the client belongs to
     */
    public function getClientNamespace(string $clientId): string
    {
        return $this->server->getNamespaceManager()->getClientNamespace($clientId);
    }

    /**
     * Gets all clients in a specific room
     *
     * @param string $room The room name to query
     * @param string $namespace The namespace containing the room (default: '/')
     * @return array<string, string> Array of client IDs in the room
     */
    public function getClientsInRoom(string $room, string $namespace = '/'): array
    {
        return $this->server->getClientsInRoom($room, $namespace);
    }

    /**
     * Gets all rooms in a namespace
     *
     * @param string $namespace The namespace to query (default: '/')
     * @return array<int, string> Array of room names
     */
    public function getRooms(string $namespace = '/'): array
    {
        return $this->server->getNamespaceManager()->getRooms($namespace);
    }

    /**
     * Gets all rooms a client belongs to
     *
     * @param string $clientId The client ID to query
     * @return array<int, string> Array of room names the client belongs to
     */
    public function getClientRooms(string $clientId): array
    {
        return $this->server->getNamespaceManager()->getClientRooms($clientId);
    }

    /**
     * Gets all connected client IDs
     *
     * @return list<string> Array of all connected client IDs
     */
    public function getClientIds(): array
    {
        return $this->server->getClientIds();
    }

    /**
     * Gets the total number of connected clients
     *
     * @return int The number of connected clients
     */
    public function getClientCount(): int
    {
        return $this->server->getClientCount();
    }

    /**
     * Checks if a client is currently connected
     *
     * @param string $clientId The client ID to check
     * @return bool True if the client is connected, false otherwise
     */
    public function isConnected(string $clientId): bool
    {
        return $this->server->isClientConnected($clientId);
    }

    /**
     * Gets the client connection type (e.g., 'ws', 'http', 'unknown')
     *
     * @param string $clientId The client ID to check
     * @return string|null The client type or null if not found
     */
    public function getClientType(string $clientId): ?string
    {
        return $this->server->getClientType($clientId);
    }

    /**
     * Gets the IP address of a client
     *
     * @param string $clientId The client ID
     * @return string|null The client IP address or null if not found
     */
    public function getClientIp(string $clientId): ?string
    {
        return $this->server->getClientIpAddress($clientId);
    }

    /**
     * Gets the server instance for advanced operations
     *
     * This provides direct access to the server for operations not covered
     * by the controller methods
     *
     * @return Server The server instance
     */
    public function getServer(): Server
    {
        return $this->server;
    }

    /**
     * Gets the namespace manager for advanced namespace operations
     *
     * @return NamespaceManagerInterface The namespace manager instance
     */
    public function getNamespaceManager(): NamespaceManagerInterface
    {
        return $this->server->getNamespaceManager();
    }

    /**
     * Gets the router for advanced routing operations
     *
     * @return Router The router instance
     */
    public function getRouter(): Router
    {
        return $this->server->getRouter();
    }

    /**
     * Gets the logger instance
     *
     * @return LoggerInterface The logger instance
     */
    public function getLogger(): LoggerInterface
    {
        return $this->server->getLogger();
    }

    /**
     * Get server uptime in seconds
     *
     * @return int|null Server uptime in seconds, or null if server hasn't started
     */
    public function getUptime(): ?int
    {
        return $this->server->getUptime();
    }

    /**
     * Get server uptime as a human-readable string
     *
     * @return string|null Human-readable uptime string (e.g., "2h 30m 15s"), or null if not started
     */
    public function getUptimeString(): ?string
    {
        return $this->server->getUptimeString();
    }

    /**
     * Get server start time
     *
     * @return float|null Unix timestamp with microseconds when server started, or null if not started
     */
    public function getStartTime(): ?float
    {
        return $this->server->getStartTime();
    }

    /**
     * Get comprehensive server statistics including scaling features
     *
     * @return array<string, mixed> Comprehensive server statistics
     */
    public function getServerStats(): array
    {
        return $this->server->getServerStats();
    }

    /**
     * Queue an async task for background processing
     *
     * @param string $type Task type
     * @param array<string, mixed> $data Task data
     * @param int $priority Priority level (higher = more important)
     */
    public function queueAsyncTask(string $type, array $data, int $priority = 0): void
    {
        $this->server->queueAsyncTask($type, $data, $priority);
    }

    /**
     * Get performance metrics from the server
     *
     * @return array<string, mixed> Performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        $stats = $this->getServerStats();
        $performance = $stats['performance'] ?? [];
        if (!is_array($performance)) {
            return [];
        }
        /** @var array<string, mixed> $performance */
        return $performance;
    }

    /**
     * Get connection pool statistics
     *
     * @return array<string, mixed> Connection pool statistics
     */
    public function getConnectionPoolStats(): array
    {
        $stats = $this->getServerStats();
        $poolStats = $stats['connection_pool'] ?? [];
        if (!is_array($poolStats)) {
            return [];
        }
        /** @var array<string, mixed> $poolStats */
        return $poolStats;
    }

    /**
     * Get async task queue statistics
     *
     * @return array<string, mixed> Task queue statistics
     */
    public function getTaskQueueStats(): array
    {
        $stats = $this->getServerStats();
        $queueStats = $stats['task_queue'] ?? [];
        if (!is_array($queueStats)) {
            return [];
        }
        /** @var array<string, mixed> $queueStats */
        return $queueStats;
    }

    /**
     * Record a performance metric
     *
     * @param string $type Metric type (http/websocket/connection/request)
     * @param float $value Metric value (response time, etc.)
     */
    public function recordMetric(string $type, float $value = 0): void
    {
        $this->server->recordRequestMetric($type, $value);
    }

    /**
     * Record an error metric
     *
     * @param string $type Error type (connection/request)
     */
    public function recordError(string $type): void
    {
        $this->server->recordErrorMetric($type);
    }
}
