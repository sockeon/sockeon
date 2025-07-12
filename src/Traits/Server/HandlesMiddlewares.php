<?php

namespace Sockeon\Sockeon\Traits\Server;

use Sockeon\Sockeon\Core\Middleware;

trait HandlesMiddlewares
{

    public function getMiddleware(): Middleware
    {
        return $this->middleware;
    }

    /**
     * Add a WebSocket middleware
     * 
     * @param string $middleware The WebSocket middleware class implementing WebsocketMiddleware
     * @return self This server instance for method chaining
     */
    public function addWebSocketMiddleware(string $middleware): self
    {
        $this->middleware->addWebSocketMiddleware($middleware);
        return $this;
    }

    /**
     * Add an HTTP middleware
     *
     * @param class-string $middleware The HTTP middleware class implementing HttpMiddleware
     * @return self This server instance for method chaining
     */
    public function addHttpMiddleware(string $middleware): self
    {
        $this->middleware->addHttpMiddleware($middleware);
        return $this;
    }
}
