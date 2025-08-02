<?php
/**
 * Router class
 *
 * Manages routing of WebSocket events and HTTP requests to controller methods
 *
 * Features:
 * - WebSocket event routing
 * - HTTP request routing with method and path matching
 * - Support for path parameters using {parameter} syntax
 * - Automatic query parameter parsing
 *
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Core;

use InvalidArgumentException;
use ReflectionClass;
use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Contracts\Http\HttpMiddleware;
use Sockeon\Sockeon\Contracts\WebSocket\WebsocketMiddleware;
use Sockeon\Sockeon\Controllers\SocketController;
use Sockeon\Sockeon\Http\Attributes\HttpRoute;
use Sockeon\Sockeon\Http\Request;
use Sockeon\Sockeon\Traits\Router\HandlesHttpDispatching;
use Sockeon\Sockeon\Traits\Router\HandlesMiddlewareValidation;
use Sockeon\Sockeon\Traits\Router\HandlesRouteRegistration;
use Sockeon\Sockeon\Traits\Router\HandlesWebSocketDispatching;
use Sockeon\Sockeon\WebSocket\Attributes\OnConnect;
use Sockeon\Sockeon\WebSocket\Attributes\OnDisconnect;
use Sockeon\Sockeon\WebSocket\Attributes\SocketOn;
use Throwable;

class Router
{
    use HandlesHttpDispatching, HandlesMiddlewareValidation, HandlesRouteRegistration, HandlesWebSocketDispatching;
    /**
     * WebSocket routes
     * @var array<string, array{0: SocketController, 1: string, 2: array<int, class-string>, 3: array<int, class-string>}>
     */
    protected array $wsRoutes = [];

    /**
     * HTTP routes
     * @var array<string, array{0: SocketController, 1: string, 2: array<int, class-string>, 3: array<int, class-string>}>
     */
    protected array $httpRoutes = [];

    /**
     * Special event handlers for connection events
     * @var array<string, array<int, array{0: SocketController, 1: string}>>
     */
    protected array $specialEventHandlers = [
        'connect' => [],
        'disconnect' => []
    ];
    
    /**
     * Server instance
     * @var Server|null
     */
    protected ?Server $server = null;

    /**
     * Set the server instance
     *
     * @param Server $server The server instance
     * @return void
     */
    public function setServer(Server $server): void
    {
        $this->server = $server;
    }

    /**
     * Get HTTP routes
     *
     * @return array<string, array{0: SocketController, 1: string, 2: array<int, class-string>, 3: array<int, class-string>}>
     */
    public function getHttpRoutes(): array
    {
        return $this->httpRoutes;
    }

    /**
     * Get WebSocket routes
     *
     * @return array<string, array{0: SocketController, 1: string, 2: array<int, class-string>, 3: array<int, class-string>}>
     */
    public function getWebSocketRoutes(): array
    {
        return $this->wsRoutes;
    }
}
