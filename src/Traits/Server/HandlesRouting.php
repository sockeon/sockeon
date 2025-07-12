<?php

namespace Sockeon\Sockeon\Traits\Server;

use Sockeon\Sockeon\Core\Router;

trait HandlesRouting
{
    /**
     * Get the router instance.
     *
     * @return Router
     */
    public function getRouter(): Router
    {
        return $this->router;
    }
}