<?php

namespace Sockeon\Sockeon\Traits\Server;

use Sockeon\Sockeon\Contracts\Namespace\NamespaceManagerInterface;

trait HandlesNamespace
{
    /**
     * Get the current namespace manager.
     */
    public function getNamespaceManager(): NamespaceManagerInterface
    {
        return $this->namespaceManager;
    }
}
