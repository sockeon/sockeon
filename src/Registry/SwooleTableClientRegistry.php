<?php

namespace Sockeon\Sockeon\Registry;

use Sockeon\Sockeon\Contracts\Registry\ClientRegistryInterface;
use Sockeon\Sockeon\Engine\SwooleEngineTables;

class SwooleTableClientRegistry implements ClientRegistryInterface
{
    protected SwooleEngineTables $tables;

    protected int $clientIdCounter = 0;

    public function __construct(SwooleEngineTables $tables)
    {
        $this->tables = $tables;
    }

    public function generateClientId(): string
    {
        $this->clientIdCounter++;

        return sprintf('c_%d_%d', (int) (microtime(true) * 1000), $this->clientIdCounter);
    }

    public function registerConnection(string $clientId, int $fd, string $type, int $workerId): bool
    {
        if (!$this->hasTableCapacity()) {
            return false;
        }

        if (!$this->tables->clients->set($clientId, [
            'fd' => $fd,
            'type' => $type,
            'worker_id' => $workerId,
        ])) {
            return false;
        }

        if (!$this->tables->fdMap->set((string) $fd, [
            'client_id' => $clientId,
        ])) {
            $this->tables->clients->del($clientId);

            return false;
        }

        return true;
    }

    public function hasTableCapacity(): bool
    {
        return $this->tables->clients->count() < $this->tables->capacity
            && $this->tables->fdMap->count() < $this->tables->capacity;
    }

    /**
     * @param callable(int): bool $isAlive
     */
    public function removeStaleConnections(callable $isAlive): int
    {
        $removed = 0;

        foreach ($this->all() as $clientId => $fd) {
            if (!$isAlive($fd)) {
                $this->remove($clientId);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @param int|resource $resource
     */
    public function add(string $clientId, $resource, string $type = 'unknown'): void
    {
        $fd = is_int($resource) ? $resource : (int) $resource;
        $this->registerConnection($clientId, $fd, $type, 0);
    }

    public function remove(string $clientId): void
    {
        $row = $this->getClientRow($clientId);
        if ($row !== null) {
            $this->tables->fdMap->del((string) $row['fd']);
        }

        $this->tables->clients->del($clientId);
    }

    public function has(string $clientId): bool
    {
        return $this->tables->clients->exists($clientId);
    }

    /**
     * @return int|null
     */
    public function getResource(string $clientId)
    {
        return $this->getFd($clientId);
    }

    public function getFd(string $clientId): ?int
    {
        $row = $this->getClientRow($clientId);

        return $row !== null ? $row['fd'] : null;
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        $clients = [];

        foreach ($this->tables->clients as $clientId => $row) {
            if (!is_string($clientId) || !is_array($row) || !isset($row['fd'])) {
                continue;
            }

            $clients[$clientId] = (int) $row['fd'];
        }

        return $clients;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        $ids = [];

        foreach ($this->tables->clients as $clientId => $row) {
            if (is_string($clientId) && is_array($row)) {
                $ids[] = $clientId;
            }
        }

        return $ids;
    }

    /**
     * @param callable(string): void $callback
     */
    public function eachWebSocketClient(callable $callback): void
    {
        foreach ($this->tables->clients as $clientId => $row) {
            if (!is_string($clientId) || !is_array($row) || ($row['type'] ?? '') !== 'ws') {
                continue;
            }

            $callback($clientId);
        }
    }

    public function count(): int
    {
        return $this->tables->clients->count();
    }

    public function getType(string $clientId): ?string
    {
        $row = $this->getClientRow($clientId);
        if ($row === null) {
            return null;
        }

        return $row['type'] !== '' ? $row['type'] : null;
    }

    public function setType(string $clientId, string $type): void
    {
        $row = $this->getClientRow($clientId);
        if ($row === null) {
            return;
        }

        $this->tables->clients->set($clientId, [
            'fd' => $row['fd'],
            'type' => $type,
            'worker_id' => $row['worker_id'],
        ]);
    }

    public function mapResource(int $resourceId, string $clientId): void
    {
        $this->tables->fdMap->set((string) $resourceId, [
            'client_id' => $clientId,
        ]);
    }

    public function unmapResource(int $resourceId): void
    {
        $this->tables->fdMap->del((string) $resourceId);
    }

    public function getClientIdByResource(int $resourceId): ?string
    {
        $row = $this->getFdMapRow($resourceId);
        if ($row === null) {
            return null;
        }

        return $row['client_id'] !== '' ? $row['client_id'] : null;
    }

    /**
     * @return array{fd: int, type: string, worker_id: int}|null
     */
    private function getClientRow(string $clientId): ?array
    {
        $row = $this->tables->clients->get($clientId);
        if (!is_array($row) || !isset($row['fd'], $row['type'], $row['worker_id'])) {
            return null;
        }

        return [
            'fd' => (int) $row['fd'],
            'type' => (string) $row['type'],
            'worker_id' => (int) $row['worker_id'],
        ];
    }

    /**
     * @return array{client_id: string}|null
     */
    private function getFdMapRow(int $resourceId): ?array
    {
        $row = $this->tables->fdMap->get((string) $resourceId);
        if (!is_array($row) || !isset($row['client_id']) || !is_string($row['client_id'])) {
            return null;
        }

        return ['client_id' => $row['client_id']];
    }
}
