<?php
/**
 * HandlesWebSocketDispatching trait
 * 
 * Manages WebSocket event dispatching and special events
 * 
 * @package     Sockeon\Sockeon\Traits\Router
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Traits\Router;

use Throwable;

trait HandlesWebSocketDispatching
{
    /**
     * Dispatch a WebSocket event to the appropriate handler
     *
     * @param int $clientId The client identifier
     * @param string $event The event name
     * @param array<string, mixed> $data The event data
     * @return void
     */
    public function dispatch(int $clientId, string $event, array $data): void
    {
        if (isset($this->wsRoutes[$event])) {
            [$controller, $method, $middlewares, $excludeGlobalMiddlewares] = $this->wsRoutes[$event];

            $this->validateWebsocketMiddlewares($middlewares);

            if ($this->server) {
                $this->server->getMiddleware()->runWebSocketStack(
                    $clientId,
                    $event,
                    $data,
                    function ($clientId, $data) use ($controller, $method) {
                        return $controller->$method($clientId, $data);
                    },
                    $this->server,
                    $middlewares,
                    $excludeGlobalMiddlewares
                );
            } else {
                $controller->$method($clientId, $data);
            }
        }
    }

    /**
     * Dispatch a special event (connect/disconnect) to all registered handlers
     * 
     * @param int $clientId The client identifier
     * @param string $eventType The special event type ('connect' or 'disconnect')
     * @return void
     */
    public function dispatchSpecialEvent(int $clientId, string $eventType): void
    {
        if (!isset($this->specialEventHandlers[$eventType])) {
            return;
        }
        
        foreach ($this->specialEventHandlers[$eventType] as [$controller, $method]) {
            try {
                if ($this->server) {
                    $this->server->getMiddleware()->runWebSocketStack(
                        $clientId, 
                        $eventType, 
                        [], 
                        function ($clientId, $data) use ($controller, $method) {
                            return $controller->$method($clientId);
                        },
                        $this->server
                    );
                } else {
                    $controller->$method($clientId);
                }
            } catch (Throwable $e) {
                if ($this->server) {
                    $this->server->getLogger()->exception($e, [
                        'context' => "Special event handler: $eventType",
                        'clientId' => $clientId,
                        'controller' => get_class($controller),
                        'method' => $method
                    ]);
                }
            }
        }
    }
}
