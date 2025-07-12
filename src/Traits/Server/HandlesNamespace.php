<?php

namespace Sockeon\Sockeon\Traits\Server;

use Sockeon\Sockeon\Core\NamespaceManager;

trait HandlesNamespace
{
    /**
     * Get the current namespace manager.
     *
     * @return NamespaceManager
     */
    public function getNamespaceManager(): NamespaceManager
    {
        return $this->namespaceManager;
    }
}