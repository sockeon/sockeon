<?php

namespace Sockeon\Sockeon\Contracts\Registry;

interface ClientRegistryInterface
{
    public function generateClientId(): string;

    /**
     * @param int|resource $resource
     */
    public function add(string $clientId, $resource, string $type = 'unknown'): void;

    public function remove(string $clientId): void;

    public function has(string $clientId): bool;

    /**
     * @return int|resource|null
     */
    public function getResource(string $clientId);

    public function getFd(string $clientId): ?int;

    /**
     * @return array<string, int|resource>
     */
    public function all(): array;

    /**
     * @return list<string>
     */
    public function ids(): array;

    public function count(): int;

    public function getType(string $clientId): ?string;

    public function setType(string $clientId, string $type): void;

    public function mapResource(int $resourceId, string $clientId): void;

    public function unmapResource(int $resourceId): void;

    public function getClientIdByResource(int $resourceId): ?string;
}
