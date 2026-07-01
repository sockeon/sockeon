<?php

namespace Sockeon\Sockeon\Publisher;

use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Contracts\Publisher\PublisherInterface;
use Throwable;

class LocalPublisher implements PublisherInterface
{
    public function __construct(private Server $server)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function broadcast(string $event, array $data, ?string $namespace = null, ?string $room = null): void
    {
        $payload = $this->server->buildEventPayloadForBroadcast($event, $data);
        $clients = $this->resolveTargetClients($namespace, $room);
        $disconnectedClients = [];

        foreach ($clients as $clientId) {
            if (!$this->server->isWebSocketClientForBroadcast($clientId)) {
                continue;
            }

            try {
                if (!$this->server->getEngine()->send($clientId, $payload)) {
                    $disconnectedClients[] = $clientId;
                }
            } catch (Throwable) {
                $disconnectedClients[] = $clientId;
            }
        }

        foreach ($disconnectedClients as $clientId) {
            $this->server->disconnectClient($clientId);
        }
    }

    public function start(): void
    {
    }

    /**
     * @return list<string>
     */
    private function resolveTargetClients(?string $namespace, ?string $room): array
    {
        if ($room !== null && $namespace !== null) {
            return array_values($this->server->getNamespaceManager()->getClientsInRoom($room, $namespace));
        }

        if ($namespace !== null) {
            return array_values($this->server->getNamespaceManager()->getClientsInNamespace($namespace));
        }

        return $this->server->getClientIds();
    }
}
