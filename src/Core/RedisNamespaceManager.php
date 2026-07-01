<?php

namespace Sockeon\Sockeon\Core;

use Redis;
use Sockeon\Sockeon\Config\ScaleConfig;
use Sockeon\Sockeon\Contracts\Namespace\NamespaceManagerInterface;

class RedisNamespaceManager implements NamespaceManagerInterface
{
    private string $prefix;

    public function __construct(
        private ScaleConfig $config,
        private Redis $redis,
    ) {
        $this->prefix = $config->getRedisPrefix();
    }

    public function joinNamespace(string $clientId, string $namespace = '/'): void
    {
        $this->leaveNamespace($clientId);

        $this->redis->sAdd($this->namespaceKey($namespace), $this->memberKey($clientId));
        $this->redis->set($this->clientNamespaceKey($clientId), $namespace);
        $this->refreshNodePresence();
    }

    public function leaveNamespace(string $clientId): void
    {
        $namespace = $this->getClientNamespace($clientId);
        if ($namespace !== '/') {
            $this->redis->sRem($this->namespaceKey($namespace), $this->memberKey($clientId));
        } elseif ($this->redis->sIsMember($this->namespaceKey('/'), $this->memberKey($clientId))) {
            $this->redis->sRem($this->namespaceKey('/'), $this->memberKey($clientId));
        }

        $this->redis->del($this->clientNamespaceKey($clientId));
        $this->leaveAllRooms($clientId);
    }

    public function joinRoom(string $clientId, string $room, string $namespace = '/'): void
    {
        $this->redis->sAdd($this->namespaceRoomsKey($namespace), $room);
        $this->redis->sAdd($this->roomKey($namespace, $room), $this->memberKey($clientId));
        $this->redis->sAdd($this->clientRoomsKey($clientId), $this->roomEntry($namespace, $room));
        $this->refreshNodePresence();
    }

    public function leaveRoom(string $clientId, string $room, string $namespace = '/'): void
    {
        $this->redis->sRem($this->roomKey($namespace, $room), $this->memberKey($clientId));
        $this->redis->sRem($this->clientRoomsKey($clientId), $this->roomEntry($namespace, $room));
    }

    public function leaveAllRooms(string $clientId): void
    {
        $entries = $this->smembers($this->clientRoomsKey($clientId));
        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            [$namespace, $room] = $this->parseRoomEntry($entry);
            $this->leaveRoom($clientId, $room, $namespace);
        }

        $this->redis->del($this->clientRoomsKey($clientId));
    }

    public function getClientsInNamespace(string $namespace = '/'): array
    {
        return $this->filterLocalMembers($this->smembers($this->namespaceKey($namespace)));
    }

    public function getClientsInRoom(string $room, string $namespace = '/'): array
    {
        return $this->filterLocalMembers($this->smembers($this->roomKey($namespace, $room)));
    }

    public function getRooms(string $namespace = '/'): array
    {
        return array_values(array_filter($this->smembers($this->namespaceRoomsKey($namespace)), 'is_string'));
    }

    public function getClientNamespace(string $clientId): string
    {
        $namespace = $this->redis->get($this->clientNamespaceKey($clientId));

        return is_string($namespace) && $namespace !== '' ? $namespace : '/';
    }

    public function getClientRooms(string $clientId): array
    {
        $entries = $this->smembers($this->clientRoomsKey($clientId));
        $rooms = [];

        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            [, $room] = $this->parseRoomEntry($entry);
            $rooms[] = $room;
        }

        return $rooms;
    }

    public function cleanup(string $clientId): void
    {
        $this->leaveNamespace($clientId);
    }

    /**
     * @return list<string>
     */
    private function smembers(string $key): array
    {
        $members = $this->redis->sMembers($key);

        return is_array($members) ? array_values(array_filter($members, 'is_string')) : [];
    }

    private function refreshNodePresence(): void
    {
        $this->redis->setex(
            $this->nodePresenceKey($this->config->getNodeId()),
            $this->config->getPresenceTtl(),
            (string) time()
        );
    }

    private function memberKey(string $clientId): string
    {
        return $this->config->getNodeId() . ':' . $clientId;
    }

    /**
     * @param array<int, mixed> $members
     * @return array<string, string>
     */
    private function filterLocalMembers(array $members): array
    {
        $nodePrefix = $this->config->getNodeId() . ':';
        $clients = [];

        foreach ($members as $member) {
            if (!is_string($member) || !str_starts_with($member, $nodePrefix)) {
                continue;
            }

            $clientId = substr($member, strlen($nodePrefix));
            if ($clientId !== '') {
                $clients[$clientId] = $clientId;
            }
        }

        return $clients;
    }

    private function namespaceKey(string $namespace): string
    {
        return $this->prefix . 'namespace:' . $namespace;
    }

    private function namespaceRoomsKey(string $namespace): string
    {
        return $this->prefix . 'namespace:' . $namespace . ':rooms';
    }

    private function roomKey(string $namespace, string $room): string
    {
        return $this->prefix . 'room:' . $namespace . ':' . $room;
    }

    private function clientNamespaceKey(string $clientId): string
    {
        return $this->prefix . 'client:' . $clientId . ':namespace';
    }

    private function clientRoomsKey(string $clientId): string
    {
        return $this->prefix . 'client:' . $clientId . ':rooms';
    }

    private function nodePresenceKey(string $nodeId): string
    {
        return $this->prefix . 'node:' . $nodeId;
    }

    private function roomEntry(string $namespace, string $room): string
    {
        return $namespace . '|' . $room;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseRoomEntry(string $entry): array
    {
        $parts = explode('|', $entry, 2);

        return [$parts[0] ?? '/', $parts[1] ?? ''];
    }
}
