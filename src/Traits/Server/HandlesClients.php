<?php

namespace Sockeon\Sockeon\Traits\Server;

use RuntimeException;
use Sockeon\Sockeon\Core\Config;
use Throwable;

trait HandlesClients
{
    protected function startSocket(): void
    {
        $this->logger->info("[Sockeon Server] Starting server...");

        $this->socket = stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if (!$this->socket) {
            $errorNumber = is_int($errno) ? $errno : 0;
            $errorString = is_string($errstr) ? $errstr : 'Unknown error';
            throw new RuntimeException("Failed to create socket: $errorString ($errorNumber)");
        }

        stream_set_blocking($this->socket, false);
        $this->logger->info("[Sockeon Server] Listening on tcp://{$this->host}:{$this->port}");
    }

    protected function loop(): void
    {
        $lastQueueCheck = microtime(true);

        while (is_resource($this->socket)) {
            try {
                if ((microtime(true) - $lastQueueCheck) > 0.2) {
                    $this->processQueue(Config::getQueueFile());
                    $lastQueueCheck = microtime(true);
                }

                /** @var array<resource> $readSockets */
                $readSockets = array_filter($this->clients, fn($client) => is_resource($client));
                $readSockets[] = $this->socket;
                /** @var array<resource> $read */
                $read = $readSockets;

                $write = $except = null;

                if (@stream_select($read, $write, $except, 0, 200000)) {
                    $this->acceptNewClient($read);
                    $this->handleClientData($read);
                }
            } catch (Throwable $e) {
                $this->logger->exception($e, ['context' => 'Main loop']);
                usleep(100000);
            }
        }
    }

    /**
     * @param array<resource> $read
     */
    protected function acceptNewClient(array &$read): void
    {
        if (in_array($this->socket, $read, true)) {
            $client = @stream_socket_accept($this->socket);

            if ($client && is_resource($client)) {
                stream_set_blocking($client, false);
                $clientId = (int) $client;
                $this->clients[$clientId] = $client;
                $this->clientTypes[$clientId] = 'unknown';
                $this->namespaceManager->joinNamespace($clientId);
                $this->logger->debug("[Sockeon Connection] Client connected: $clientId");
            }

            unset($read[array_search($this->socket, $read, true)]);
        }
    }

    /**
     * @param array<resource> $read
     */
    protected function handleClientData(array $read): void
    {
        foreach ($read as $client) {
            $clientId = (int)$client;

            try {
                $data = fread($client, 8192);

                if ($data === '' || $data === false) {
                    $this->disconnectClient($clientId);
                    continue;
                }

                $this->handleHttpWs($clientId, $client, $data);

            } catch (Throwable $e) {
                $this->logger->exception($e, ['clientId' => $clientId]);
                $this->disconnectClient($clientId);
            }
        }
    }

    public function disconnectClient(int $clientId): void
    {
        try {
            if (isset($this->clients[$clientId])) {
                if (($this->clientTypes[$clientId] ?? null) === 'ws') {
                    $this->router->dispatchSpecialEvent($clientId, 'disconnect');
                }

                if (is_resource($this->clients[$clientId])) {
                    fclose($this->clients[$clientId]);
                }
                unset($this->clients[$clientId], $this->clientTypes[$clientId], $this->clientData[$clientId]);
                $this->namespaceManager->leaveNamespace($clientId);

                $this->logger->debug("[Sockeon Connection] Client disconnected: $clientId");
            }
        } catch (Throwable $e) {
            $this->logger->exception($e, ['context' => 'Client disconnection', 'clientId' => $clientId]);
        }
    }

    public function setClientData(int $clientId, string $key, mixed $value): void
    {
        $this->clientData[$clientId][$key] = $value;
    }

    public function getClientData(int $clientId, ?string $key = null): mixed
    {
        if (!isset($this->clientData[$clientId])) {
            return null;
        }

        return $key === null ? $this->clientData[$clientId] : ($this->clientData[$clientId][$key] ?? null);
    }
}
