<?php

namespace Sockeon\Sockeon\Contracts\Publisher;

interface PublisherInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function broadcast(string $event, array $data, ?string $namespace = null, ?string $room = null): void;

    /**
     * Start background subscribers (e.g. Redis pub/sub). No-op for local-only mode.
     */
    public function start(): void;
}
