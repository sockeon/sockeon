<?php

namespace Sockeon\Sockeon\Contracts\Namespace;

interface NamespaceManagerInterface
{
    public function joinNamespace(string $clientId, string $namespace = '/'): void;

    public function leaveNamespace(string $clientId): void;

    public function joinRoom(string $clientId, string $room, string $namespace = '/'): void;

    public function leaveRoom(string $clientId, string $room, string $namespace = '/'): void;

    public function leaveAllRooms(string $clientId): void;

    /**
     * @return array<string, string>
     */
    public function getClientsInNamespace(string $namespace = '/'): array;

    /**
     * @return array<string, string>
     */
    public function getClientsInRoom(string $room, string $namespace = '/'): array;

    /**
     * @return array<int, string>
     */
    public function getRooms(string $namespace = '/'): array;

    public function getClientNamespace(string $clientId): string;

    /**
     * @return array<int, string>
     */
    public function getClientRooms(string $clientId): array;

    public function cleanup(string $clientId): void;
}
