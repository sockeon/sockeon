<?php

namespace Sockeon\Sockeon\Traits\Server;

use Throwable;

trait HandlesSendBroadcast
{
    /**
     * Send a WebSocket message to a specific client
     *
     * @param string $clientId The client ID to send to
     * @param string $event Event name
     * @param array<string, mixed> $data Data to send
     * @return void
     */
    public function emit(string $clientId, string $event, array $data): void
    {
        if (!$this->isWebSocketClient($clientId)) {
            return;
        }

        try {
            $payload = $this->buildEventPayload($event, $data);
            if (!$this->engine->send($clientId, $payload)) {
                $this->disconnectClient($clientId);
            }
        } catch (Throwable $e) {
            $this->disconnectClient($clientId);
        }
    }

    /**
     * Send raw message data to a specific client
     *
     * @param string $clientId The client ID to send to
     * @param string $message Raw message data
     * @return void
     */
    public function sendRaw(string $clientId, string $message): void
    {
        if (!$this->isWebSocketClient($clientId)) {
            return;
        }

        try {
            $payload = $this->buildWebSocketOutboundPayload($message);
            if (!$this->engine->send($clientId, $payload)) {
                $this->disconnectClient($clientId);
            }
        } catch (Throwable $e) {
            $this->disconnectClient($clientId);
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
        $this->publisher->broadcast($event, $data, $namespace, $room);
    }

    public function isWebSocketClientForBroadcast(string $clientId): bool
    {
        return $this->isWebSocketClient($clientId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function buildEventPayloadForBroadcast(string $event, array $data): string
    {
        return $this->buildEventPayload($event, $data);
    }

    protected function isWebSocketClient(string $clientId): bool
    {
        return $this->isClientConnected($clientId) && $this->getClientType($clientId) === 'ws';
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function buildEventPayload(string $event, array $data): string
    {
        return $this->buildWebSocketOutboundPayload($this->wsHandler->encodeEventPayload($event, $data));
    }

    protected function buildWebSocketOutboundPayload(string $jsonPayload): string
    {
        return $this->engine->framesOutboundWebSocket()
            ? $this->wsHandler->encodeWebSocketFrame($jsonPayload)
            : $jsonPayload;
    }
}
