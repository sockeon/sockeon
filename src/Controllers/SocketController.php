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
     * @var Server
     */
    private Server $server;

    /**
     * Sets the server instance for this controller
     *
     * This method is called by the server when registering the controller
     *
     * @param Server $server The server instance to set
     * @return void
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
     * @return void
     */
    public function emit(string $clientId, string $event, array $data): void
    {
        $this->server->send($clientId, $event, $data);
    }

    /**
     * Broadcasts an event to multiple clients
     *
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     * @param string|null $namespace Optional namespace to broadcast within
     * @param string|null $room Optional room to broadcast to
     * @return void
     */
    public function broadcast(string $event, array $data, ?string $namespace = null, ?string $room = null): void
    {
        $this->server->broadcast($event, $data, $namespace, $room);
    }

    /**
     * Broadcasts an event to specified clients
     *
     * @param list<string> $clients The array of the client ids to broadcast
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     * @return void
     */
    public function broadcastTo(array $clients, string $event, array $data): void
    {
        foreach ($clients as $client) {
            $this->emit($client, $event, $data);
        }
    }

    /**
     * Broadcasts an event to all clients except the given ones
     *
     * @param list<string> $excepts The array of the client ids to ignore
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     * @return void
     */
    public function broadcastExcept(array $excepts, string $event, array $data): void
    {
        $clients = $this->getAllClients();
        foreach ($clients as $client) {
            if (!in_array($client, $excepts)) {
                $this->emit($client, $event, $data);
            }
        }
    }

    /**
     * Adds a client to a room
     *
     * @param string $clientId The ID of the client to add
     * @param string $room The room name
     * @param string $namespace The namespace
     * @return void
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
     * @return void
     */
    public function leaveRoom(string $clientId, string $room, string $namespace = '/'): void
    {
        $this->server->leaveRoom($clientId, $room, $namespace);
    }

    /**
     * Disconnects a client from the server
     *
     * Closes the connection and cleans up all client resources
     *
     * @param string $clientId The ID of the client to disconnect
     * @return void
     */
    public function disconnectClient(string $clientId): void
    {
        $this->server->disconnectClient($clientId);
    }

    /**
     * Gets data for a specific client
     *
     * @param string $clientId The ID of the client to get data for
     * @param string|null $key Optional key to retrieve specific data
     * @return mixed The data for the client, or null if not found
     */
    public function getClientData(string $clientId, ?string $key = null): mixed
    {
        return $this->server->getClientData($clientId, $key);
    }

    /**
     * Sets data for a specific client
     *
     * @param string $clientId The ID of the client to set data for
     * @param string $key The key to set in the client's data
     * @param mixed $value The value to set for the key
     * @return void
     */
    public function setClientData(string $clientId, string $key, mixed $value): void
    {
        $this->server->setClientData($clientId, $key, $value);
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
     * Joins a client to a specific namespace
     *
     * @param string $clientId The client ID to move
     * @param string $namespace The namespace to join
     * @return void
     */
    public function moveClientToNamespace(string $clientId, string $namespace = '/'): void
    {
        $this->server->getNamespaceManager()->joinNamespace($clientId, $namespace);
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
     * Removes a client from all rooms they belong to
     *
     * @param string $clientId The client ID to remove from all rooms
     * @return void
     */
    public function leaveAllRooms(string $clientId): void
    {
        $this->server->getNamespaceManager()->leaveAllRooms($clientId);
    }

    /**
     * Broadcasts an event to all clients in a specific namespace
     *
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     * @param string $namespace The namespace to broadcast to
     * @return void
     */
    public function broadcastToNamespaceClients(string $event, array $data, string $namespace): void
    {
        $this->server->broadcast($event, $data, $namespace);
    }

    /**
     * Broadcasts an event to all clients in a specific room
     *
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     * @param string $room The room to broadcast to
     * @param string $namespace The namespace containing the room (default: '/')
     * @return void
     */
    public function broadcastToRoomClients(string $event, array $data, string $room, string $namespace = '/'): void
    {
        $this->server->broadcast($event, $data, $namespace, $room);
    }

    /**
     * Broadcasts an event to all connected clients
     *
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     * @return void
     */
    public function broadcastToAll(string $event, array $data): void
    {
        $this->server->broadcast($event, $data);
    }

    /**
     * Gets all connected client IDs
     *
     * @return list<string> Array of all connected client IDs
     */
    public function getAllClients(): array
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
    public function isClientConnected(string $clientId): bool
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
     * @return void
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
     * Get client IP address
     *
     * @param string $clientId The client ID
     * @return string|null The client IP address or null if not found
     */
    public function getClientIpAddress(string $clientId): ?string
    {
        return $this->server->getClientIpAddress($clientId);
    }

    /**
     * Record a performance metric
     *
     * @param string $type Metric type (http/websocket/connection/request)
     * @param float $value Metric value (response time, etc.)
     * @return void
     */
    public function recordMetric(string $type, float $value = 0): void
    {
        $this->server->recordRequestMetric($type, $value);
    }

    /**
     * Record an error metric
     *
     * @param string $type Error type (connection/request)
     * @return void
     */
    public function recordError(string $type): void
    {
        $this->server->recordErrorMetric($type);
    }
}
