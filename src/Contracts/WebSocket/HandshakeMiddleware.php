<?php
/**
 * HandshakeMiddleware interface
 *
 * This interface defines the contract for WebSocket handshake middleware in the Sockeon framework.
 * Handshake middleware allows users to customize and validate WebSocket handshake requests before
 * the connection is established.
 *
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Contracts\WebSocket;

use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\WebSocket\HandshakeRequest;

interface HandshakeMiddleware
{
        /**
     * Handle the handshake middleware
     *
     * @param int $clientId The client ID that is connecting.
     * @param HandshakeRequest $request The handshake request object.
     * @param callable $next The next middleware or handler to call.
     * @param Server $server The server instance handling the WebSocket handshake.
     * @return bool|array<string, mixed> True to continue handshake, false to reject, or array with custom response
     */
    public function handle(int $clientId, HandshakeRequest $request, callable $next, Server $server): bool|array;
}
