<?php

namespace Sockeon\Sockeon\Contracts\Engine;

use Sockeon\Sockeon\Connection\Server;

interface EngineInterface
{
    public function setServer(Server $server): void;

    /**
     * Bind the listen socket and run the event loop until stopped.
     */
    public function start(): void;

    /**
     * Send a pre-framed WebSocket payload to a client.
     */
    public function send(string $clientId, string $payload): bool;

    public function closeConnection(string $clientId, ?int $fd = null): void;

    /**
     * Remove client tracking without closing the underlying connection.
     */
    public function forgetClient(string $clientId): void;

    /**
     * Whether outbound WebSocket data must be framed by the caller (stream_select)
     * or is framed by the engine (Swoole).
     */
    public function framesOutboundWebSocket(): bool;

    public function getName(): string;
}
