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
use Sockeon\Sockeon\Core\NamespaceManager;
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
     * @param int $clientId The ID of the client to send to
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     * @return void
     */
    public function emit(int $clientId, string $event, array $data): void
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
     * Adds a client to a room
     * 
     * @param int $clientId The ID of the client to add
     * @param string $room The room name
     * @param string $namespace The namespace
     * @return void
     */
    public function joinRoom(int $clientId, string $room, string $namespace = '/'): void
    {
        $this->server->joinRoom($clientId, $room, $namespace);
    }
    
    /**
     * Removes a client from a room
     * 
     * @param int $clientId The ID of the client to remove
     * @param string $room The room name to leave
     * @param string $namespace The namespace containing the room
     * @return void
     */
    public function leaveRoom(int $clientId, string $room, string $namespace = '/'): void
    {
        $this->server->leaveRoom($clientId, $room, $namespace);
    }
    
    /**
     * Disconnects a client from the server
     * 
     * Closes the connection and cleans up all client resources
     * 
     * @param int $clientId The ID of the client to disconnect
     * @return void
     */
    public function disconnectClient(int $clientId): void
    {
        $this->server->disconnectClient($clientId);
    }

    /**
     * Gets data for a specific client
     * 
     * @param int $clientId The ID of the client to get data for
     * @param string|null $key Optional key to retrieve specific data
     * @return mixed The data for the client, or null if not found
     */
    public function getClientData(int $clientId, ?string $key = null): mixed
    {
        return $this->server->getClientData($clientId, $key);
    }

    /**
     * Sets data for a specific client
     * 
     * @param int $clientId The ID of the client to set data for
     * @param string $key The key to set in the client's data
     * @param mixed $value The value to set for the key
     * @return void
     */
    public function setClientData(int $clientId, string $key, mixed $value): void
    {
        $this->server->setClientData($clientId, $key, $value);
    }

    /**
     * Gets all clients in a specific namespace
     * 
     * @param string $namespace The namespace to query (default: '/')
     * @return array<int, int> Array of client IDs in the namespace
     */
    public function getClientsInNamespace(string $namespace = '/'): array
    {
        return $this->server->getNamespaceManager()->getClientsInNamespace($namespace);
    }

    /**
     * Gets the namespace a client belongs to
     * 
     * @param int $clientId The client ID to query
     * @return string The namespace the client belongs to
     */
    public function getClientNamespace(int $clientId): string
    {
        return $this->server->getNamespaceManager()->getClientNamespace($clientId);
    }

    /**
     * Joins a client to a specific namespace
     * 
     * @param int $clientId The client ID to move
     * @param string $namespace The namespace to join
     * @return void
     */
    public function moveClientToNamespace(int $clientId, string $namespace = '/'): void
    {
        $this->server->getNamespaceManager()->joinNamespace($clientId, $namespace);
    }

    /**
     * Gets all clients in a specific room
     * 
     * @param string $room The room name to query
     * @param string $namespace The namespace containing the room (default: '/')
     * @return array<int, int> Array of client IDs in the room
     */
    public function getClientsInRoom(string $room, string $namespace = '/'): array
    {
        return $this->server->getNamespaceManager()->getClientsInRoom($room, $namespace);
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
     * @param int $clientId The client ID to query
     * @return array<int, string> Array of room names the client belongs to
     */
    public function getClientRooms(int $clientId): array
    {
        return $this->server->getNamespaceManager()->getClientRooms($clientId);
    }

    /**
     * Removes a client from all rooms they belong to
     * 
     * @param int $clientId The client ID to remove from all rooms
     * @return void
     */
    public function leaveAllRooms(int $clientId): void
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
     * @return array<int, int> Array of all connected client IDs
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
     * @param int $clientId The client ID to check
     * @return bool True if the client is connected, false otherwise
     */
    public function isClientConnected(int $clientId): bool
    {
        return $this->server->isClientConnected($clientId);
    }

    /**
     * Gets the client connection type (e.g., 'ws', 'http', 'unknown')
     * 
     * @param int $clientId The client ID to check
     * @return string|null The client type or null if not found
     */
    public function getClientType(int $clientId): ?string
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
     * @return NamespaceManager The namespace manager instance
     */
    public function getNamespaceManager(): NamespaceManager
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
}
