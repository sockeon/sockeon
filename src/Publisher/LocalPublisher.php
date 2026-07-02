<?php

namespace Sockeon\Sockeon\Publisher;

use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Contracts\Publisher\PublisherInterface;
use Sockeon\Sockeon\Engine\SwooleEngine;
use Throwable;

class LocalPublisher implements PublisherInterface
{
    public function __construct(private Server $server) {}

    /**
     * @param array<string, mixed> $data
     */
    public function broadcast(string $event, array $data, ?string $namespace = null, ?string $room = null): void
    {
        $payload = $this->server->buildEventPayloadForBroadcast($event, $data);
        $disconnectedClients = [];

        if ($room === null && $namespace === null && $this->server->getEngine()->getName() === 'swoole') {
            $engine = $this->server->getEngine();
            if ($engine instanceof SwooleEngine) {
                $engine->getClientRegistry()->eachWebSocketClient(function (string $clientId) use ($payload, &$disconnectedClients): void {
                    try {
                        if (!$this->server->getEngine()->send($clientId, $payload)) {
                            $disconnectedClients[] = $clientId;
                        }
                    } catch (Throwable) {
                        $disconnectedClients[] = $clientId;
                    }
                });

                foreach ($disconnectedClients as $clientId) {
                    $this->server->disconnectClient($clientId);
                }

                return;
            }
        }

        $clients = $this->resolveTargetClients($namespace, $room);

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

    public function start(): void {}

    /**
     * @return list<string>
     */
    private function resolveTargetClients(?string $namespace, ?string $room): array
    {
        if ($room !== null && $namespace !== null) {
            return array_values($this->server->getClientsInRoom($room, $namespace));
        }

        if ($namespace !== null) {
            return array_values($this->server->getClientsInNamespace($namespace));
        }

        return $this->server->getClientIds();
    }
}
