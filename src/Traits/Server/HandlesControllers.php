<?php

namespace Sockeon\Sockeon\Traits\Server;

use Sockeon\Sockeon\Controllers\SocketController;
use Throwable;

trait HandlesControllers
{
    /**
     * Register a controller instance with the server
     *
     * @param SocketController $controller The controller instance to register
     * @return void
     */
    public function registerController(SocketController $controller): void
    {
        try {
            $controller->setServer($this);
            $this->router->setServer($this);
            $this->router->register($controller);
        } catch (Throwable $e) {
            $this->logger->exception($e);
        }
    }

    /**
     * Register multiple controllers with the server.
     * Accepts either controller instances or class names.
     *
     * @param array<SocketController|string> $controllers
     * @return void
     */
    public function registerControllers(array $controllers): void
    {
        foreach ($controllers as $controller) {
            try {
                if (is_string($controller)) {
                    if (class_exists($controller) && is_subclass_of($controller, SocketController::class)) {
                        $controller = new $controller();
                        $this->registerController($controller);
                    } else {
                        $this->logger->warning("[Sockeon Server] Invalid controller class: $controller");
                    }
                } else {
                    $this->registerController($controller);
                }
            } catch (Throwable $e) {
                $this->logger->exception($e);
            }
        }
    }
}
