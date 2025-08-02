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
        $lastBufferCleanup = microtime(true);

        while (is_resource($this->socket)) {
            try {
                if ((microtime(true) - $lastQueueCheck) > 0.2) {
                    $this->processQueue(Config::getQueueFile());
                    $lastQueueCheck = microtime(true);
                }

                if ((microtime(true) - $lastBufferCleanup) > 10) {
                    $this->cleanupExpiredBuffers();
                    $lastBufferCleanup = microtime(true);
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
     * Client buffers for incomplete requests
     * @var array<int, string>
     */
    protected array $clientBuffers = [];

    /**
     * Client buffer timestamps to handle timeouts
     * @var array<int, float>
     */
    protected array $clientBufferTimestamps = [];

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
                
                if (($this->clientTypes[$clientId] ?? 'unknown') === 'ws') {
                    $this->handleHttpWs($clientId, $client, $data);
                } else {
                    if (!isset($this->clientBuffers[$clientId])) {
                        $this->clientBuffers[$clientId] = '';
                        $this->clientBufferTimestamps[$clientId] = microtime(true);
                    }
                    $this->clientBuffers[$clientId] .= $data;
                    
                    if ($this->isCompleteHttpRequest($this->clientBuffers[$clientId])) {
                        $this->handleHttpWs($clientId, $client, $this->clientBuffers[$clientId]);
                        unset($this->clientBuffers[$clientId], $this->clientBufferTimestamps[$clientId]);
                    } else {
                        if (microtime(true) - $this->clientBufferTimestamps[$clientId] > 30) {
                            $this->logger->warning("Client buffer timeout for client: $clientId");
                            $this->disconnectClient($clientId);
                        }
                    }
                }
            } catch (Throwable $e) {
                $this->logger->exception($e, ['clientId' => $clientId, 'context' => 'handleClientData']);
                $this->disconnectClient($clientId);
            }
        }
    }

    /**
     * Check if we have received a complete HTTP request
     * 
     * @param string $data The buffered request data
     * @return bool True if the request is complete
     */
    protected function isCompleteHttpRequest(string $data): bool
    {
        if (!str_contains($data, "\r\n\r\n")) {
            return false;
        }

        $headerEndPos = strpos($data, "\r\n\r\n");
        if ($headerEndPos === false) {
            return false;
        }
        
        $headerSection = substr($data, 0, $headerEndPos);
        $bodySection = substr($data, $headerEndPos + 4);
        
        $contentLength = 0;
        $transferEncoding = '';
        $lines = explode("\r\n", $headerSection);
        
        foreach ($lines as $line) {
            if (stripos($line, 'Content-Length:') === 0) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $contentLength = (int) trim($parts[1]);
                }
            } elseif (stripos($line, 'Transfer-Encoding:') === 0) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $transferEncoding = strtolower(trim($parts[1]));
                }
            }
        }
        
        if ($transferEncoding === 'chunked') {
            return $this->isCompleteChunkedRequest($bodySection);
        }
        
        if ($contentLength === 0) {
            return true;
        }
        
        return strlen($bodySection) >= $contentLength;
    }

    /**
     * Check if a chunked request is complete
     * 
     * @param string $body The request body
     * @return bool True if the chunked request is complete
     */
    protected function isCompleteChunkedRequest(string $body): bool
    {
        return str_ends_with($body, "0\r\n\r\n");
    }

    /**
     * Clean up expired client buffers
     * 
     * @return void
     */
    protected function cleanupExpiredBuffers(): void
    {
        $currentTime = microtime(true);
        foreach ($this->clientBufferTimestamps as $clientId => $timestamp) {
            if ($currentTime - $timestamp > 30) {
                $this->logger->warning("Cleaning up expired buffer for client: $clientId");
                unset($this->clientBuffers[$clientId], $this->clientBufferTimestamps[$clientId]);
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
                if (isset($this->clientBuffers[$clientId])) {
                    unset($this->clientBuffers[$clientId], $this->clientBufferTimestamps[$clientId]);
                }
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

    /**
     * Get the IP address of a client
     * 
     * @param int $clientId The client ID
     * @return string|null The client IP address or null if not found
     */
    public function getClientIpAddress(int $clientId): ?string
    {
        if (!isset($this->clients[$clientId]) || !is_resource($this->clients[$clientId])) {
            return null;
        }

        $peerName = stream_socket_get_name($this->clients[$clientId], true);
        if ($peerName === false) {
            return null;
        }

        // Extract IP from the peer name (format: "ip:port")
        $parts = explode(':', $peerName);
        return $parts[0];
    }
}
