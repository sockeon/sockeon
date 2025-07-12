<?php

namespace Sockeon\Sockeon\Traits\Server;

use Sockeon\Sockeon\Contracts\LoggerInterface;

trait HandlesLogging
{
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }
}