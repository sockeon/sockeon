<?php

namespace Sockeon\Sockeon\Traits\Server;

trait HandlesSendBroadcast
{
    /**
     * Send a WebSocket message to a specific client
     * 
     * @param int $clientId The client ID to send to
     * @param string $event Event name
     * @param array<string, mixed> $data Data to send
     * @return void
     */
    public function send(int $clientId, string $event, array $data): void
    {
        if (isset($this->clients[$clientId]) && ($this->clientTypes[$clientId] ?? '') === 'ws' &&is_resource($this->clients[$clientId])) {
            $frame = $this->wsHandler->prepareMessage($event, $data);
            fwrite($this->clients[$clientId], $frame);
        }
    }

    /**
     * Broadcast a WebSocket message to multiple clients, optionally filtered by namespace and room
     * 
     * @param string $event Event name
     * @param array<string, mixed> $data Data to broadcast
     * @param string|null $namespace Optional namespace filter
     * @param string|null $room Optional room filter
     * @return void
     */
    public function broadcast(string $event, array $data, ?string $namespace = null, ?string $room = null): void
    {
        $frame = $this->wsHandler->prepareMessage($event, $data);

        if ($room !== null && $namespace !== null) {
            $clients = $this->namespaceManager->getClientsInRoom($room, $namespace);
        } elseif ($namespace !== null) {
            $clients = $this->namespaceManager->getClientsInNamespace($namespace);
        } else {
            $clients = array_keys($this->clients);
        }

        foreach ($clients as $clientId) {
            if (isset($this->clients[$clientId]) &&($this->clientTypes[$clientId] ?? '') === 'ws' &&is_resource($this->clients[$clientId])) {
                fwrite($this->clients[$clientId], $frame);
            }
        }
    }
}
