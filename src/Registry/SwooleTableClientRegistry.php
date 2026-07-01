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

        return sprintf(
            'sockeon_%s_%d_%s',
            base_convert((string) microtime(true), 10, 36),
            $this->clientIdCounter,
            bin2hex(random_bytes(4))
        );
    }

    public function registerConnection(string $clientId, int $fd, string $type, int $workerId): void
    {
        $this->tables->clients->set($clientId, [
            'fd' => $fd,
            'type' => $type,
            'worker_id' => $workerId,
        ]);

        $this->tables->fdMap->set((string) $fd, [
            'client_id' => $clientId,
        ]);
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
        $row = $this->tables->clients->get($clientId);
        if ($row !== false) {
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
        $row = $this->tables->clients->get($clientId);
        if ($row === false) {
            return null;
        }

        return (int) $row['fd'];
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        $clients = [];

        foreach ($this->tables->clients as $clientId => $row) {
            $clients[$clientId] = (int) $row['fd'];
        }

        return $clients;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->all());
    }

    public function count(): int
    {
        return $this->tables->clients->count();
    }

    public function getType(string $clientId): ?string
    {
        $row = $this->tables->clients->get($clientId);
        if ($row === false) {
            return null;
        }

        $type = (string) $row['type'];

        return $type !== '' ? $type : null;
    }

    public function setType(string $clientId, string $type): void
    {
        $row = $this->tables->clients->get($clientId);
        if ($row === false) {
            return;
        }

        $this->tables->clients->set($clientId, [
            'fd' => (int) $row['fd'],
            'type' => $type,
            'worker_id' => (int) $row['worker_id'],
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
        $row = $this->tables->fdMap->get((string) $resourceId);
        if ($row === false) {
            return null;
        }

        $clientId = (string) $row['client_id'];

        return $clientId !== '' ? $clientId : null;
    }
}
