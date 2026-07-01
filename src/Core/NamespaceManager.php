<?php

/**
 * NamespaceManager class
 *
 * Manages namespaces and rooms for WebSocket connections
 *
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Core;

use Sockeon\Sockeon\Contracts\Namespace\NamespaceManagerInterface;

class NamespaceManager implements NamespaceManagerInterface
{
    /**
     * The namespaces and clients within them
     * @var array<string, array<string, string>>
     */
    protected array $namespaces = [
        '/' => [],
    ];

    /**
     * Room definitions
     * @var array<string, array<string, array<string, string>>>
     */
    protected array $rooms = [];

    /**
     * Map of which clients belong to which namespaces
     * @var array<string, string>
     */
    protected array $clientNamespaces = [];

    /**
     * Map of which clients belong to which rooms
     * @var array<string, array<string, string>>
     */
    protected array $clientRooms = [];

    /**
     * Add a client to a namespace
     *
     * @param string $clientId The client ID to add
     * @param string $namespace The namespace to join
     * @return void
     */
    public function joinNamespace(string $clientId, string $namespace = '/'): void
    {
        if (!isset($this->namespaces[$namespace])) {
            $this->namespaces[$namespace] = [];
        }

        $this->namespaces[$namespace][$clientId] = $clientId;
        $this->clientNamespaces[$clientId] = $namespace;
    }

    /**
     * Remove a client from its namespace
     *
     * @param string $clientId The client ID to remove
     * @return void
     */
    public function leaveNamespace(string $clientId): void
    {
        $namespace = $this->clientNamespaces[$clientId] ?? '/';

        unset($this->namespaces[$namespace][$clientId]);
        unset($this->clientNamespaces[$clientId]);

        $this->leaveAllRooms($clientId);
    }

    /**
     * Add a client to a room within a namespace
     *
     * @param string $clientId The client ID to add
     * @param string $room The room name to join
     * @param string $namespace The namespace containing the room
     * @return void
     */
    public function joinRoom(string $clientId, string $room, string $namespace = '/'): void
    {
        if (!isset($this->rooms[$namespace])) {
            $this->rooms[$namespace] = [];
        }

        if (!isset($this->rooms[$namespace][$room])) {
            $this->rooms[$namespace][$room] = [];
        }

        $this->rooms[$namespace][$room][$clientId] = $clientId;

        if (!isset($this->clientRooms[$clientId])) {
            $this->clientRooms[$clientId] = [];
        }

        $this->clientRooms[$clientId][$room] = $namespace;
    }

    /**
     * Remove a client from a room
     *
     * @param string $clientId The client ID to remove
     * @param string $room The room name to leave
     * @param string $namespace The namespace containing the room
     * @return void
     */
    public function leaveRoom(string $clientId, string $room, string $namespace = '/'): void
    {
        if (isset($this->rooms[$namespace][$room][$clientId])) {
            unset($this->rooms[$namespace][$room][$clientId]);
            unset($this->clientRooms[$clientId][$room]);
        }
    }

    /**
     * Remove a client from all rooms
     *
     * @param string $clientId The client ID to remove from all rooms
     * @return void
     */
    public function leaveAllRooms(string $clientId): void
    {
        if (!isset($this->clientRooms[$clientId])) {
            return;
        }

        $clientRooms = $this->clientRooms[$clientId];
        foreach ($clientRooms as $room => $namespace) {
            $this->leaveRoom($clientId, $room, $namespace);
        }

        unset($this->clientRooms[$clientId]);
    }

    /**
     * Get all clients in a namespace
     *
     * @param string $namespace The namespace to query
     * @return array<string, string> Array of client IDs
     */
    public function getClientsInNamespace(string $namespace = '/'): array
    {
        return $this->namespaces[$namespace] ?? [];
    }

    /**
     * Get all clients in a room
     *
     * @param string $room The room name
     * @param string $namespace The namespace containing the room
     * @return array<string, string> Array of client IDs
     */
    public function getClientsInRoom(string $room, string $namespace = '/'): array
    {
        return $this->rooms[$namespace][$room] ?? [];
    }

    /**
     * Get all rooms in a namespace
     *
     * @param string $namespace The namespace to query
     * @return array<int, string> Array of room names
     */
    public function getRooms(string $namespace = '/'): array
    {
        return array_keys($this->rooms[$namespace] ?? []);
    }

    /**
     * Get the namespace a client belongs to
     *
     * @param string $clientId The client ID to query
     * @return string The namespace the client belongs to
     */
    public function getClientNamespace(string $clientId): string
    {
        return $this->clientNamespaces[$clientId] ?? '/';
    }

    /**
     * Get all rooms a client belongs to
     *
     * @param string $clientId The client ID to query
     * @return array<int, string> Array of room names the client belongs to
     */
    public function getClientRooms(string $clientId): array
    {
        return array_keys($this->clientRooms[$clientId] ?? []);
    }

    /**
     * Clean up all references to a client
     *
     * Removes the client from its namespace and all rooms.
     * Use this when a client disconnects.
     *
     * @param string $clientId The client ID to clean up
     * @return void
     */
    public function cleanup(string $clientId): void
    {
        $this->leaveNamespace($clientId);
    }
}
