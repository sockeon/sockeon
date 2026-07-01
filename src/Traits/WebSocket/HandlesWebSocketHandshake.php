<?php

/**
 * HandlesWebSocketHandshake trait
 *
 * Manages WebSocket handshake process and authentication
 *
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Traits\WebSocket;

use Sockeon\Sockeon\Core\Config;
use Sockeon\Sockeon\WebSocket\HandshakeRequest;

trait HandlesWebSocketHandshake
{
    /**
     * Perform WebSocket handshake with client
     *
     * @param string $clientId The client identifier
     * @param resource $client The client socket resource
     * @param string $data The HTTP handshake request
     * @return bool Whether the handshake was successful
     */
    protected function performHandshake(string $clientId, $client, string $data): bool
    {
        $handshakeRequest = new HandshakeRequest($data);

        /** @var bool|array<string, mixed> $result */
        $result = $this->server->getMiddleware()->runHandshakeStack(
            $clientId,
            $handshakeRequest,
            function (string $clientId, HandshakeRequest $request) use ($client) {
                return $this->executeHandshake($clientId, $client, $request);
            },
            $this->server
        );

        if ($result === false) {
            $this->sendCustomResponse($client, []);
        }

        if (is_array($result)) {
            $this->sendCustomResponse($client, $result);
            return false;
        }

        return $result;
    }

    /**
     * Execute the actual handshake process (after middleware)
     *
     * @param string $clientId The client identifier
     * @param resource $client The client socket resource
     * @param HandshakeRequest $request The handshake request
     * @return bool Whether the handshake was successful
     */
    protected function executeHandshake(string $clientId, $client, HandshakeRequest $request): bool
    {
        // Check origin
        $origin = $request->getOrigin();
        if ($origin !== null && !$this->isOriginAllowed($origin)) {
            $response = "HTTP/1.1 403 Forbidden\r\nContent-Type: text/plain\r\n\r\nOrigin not allowed";
            fwrite($client, $response);
            return false;
        }

        $authKey = Config::getAuthKey();
        if ($authKey !== null) {
            $keyParam = $request->getQueryParam('key');

            if ($keyParam === null || $keyParam !== $authKey) {
                $response = "HTTP/1.1 401 Unauthorized\r\nContent-Type: text/plain\r\n\r\nInvalid authentication key";
                fwrite($client, $response);
                $this->server->getLogger()->debug("[WebSocket Authentication] Authentication failed for client: $clientId");
                return false;
            }

            $this->server->getLogger()->debug("[WebSocket Authentication] Authentication successful for client: $clientId");
        }

        if (!$request->isValidWebSocketRequest()) {
            $response = "HTTP/1.1 400 Bad Request\r\nContent-Type: text/plain\r\n\r\nInvalid WebSocket request";
            fwrite($client, $response);
            return false;
        }

        $webSocketKey = $request->getWebSocketKey();
        if ($webSocketKey === null) {
            return false;
        }

        $acceptKey = base64_encode(sha1($webSocketKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $headers = [
            "HTTP/1.1 101 Switching Protocols",
            "Upgrade: websocket",
            "Connection: Upgrade",
            "Sec-WebSocket-Accept: $acceptKey",
            "Sec-WebSocket-Version: 13",
        ];

        if ($origin !== null && $this->isOriginAllowed($origin)) {
            $headers[] = "Access-Control-Allow-Origin: $origin";
        }

        $response = implode("\r\n", $headers) . "\r\n\r\n";
        fwrite($client, $response);
        $this->handshakes[$clientId] = true;

        $this->server->getRouter()->dispatchSpecialEvent($clientId, 'connect');

        return true;
    }

    /**
     * Validate handshake rules without writing an HTTP upgrade response (Swoole path).
     */
    public function validateHandshakeRequest(string $clientId, HandshakeRequest $request): bool
    {
        $origin = $request->getOrigin();
        if ($origin !== null && !$this->isOriginAllowed($origin)) {
            return false;
        }

        $authKey = Config::getAuthKey();
        if ($authKey !== null) {
            $keyParam = $request->getQueryParam('key');

            if ($keyParam === null || $keyParam !== $authKey) {
                $this->server->getLogger()->debug("[WebSocket Authentication] Authentication failed for client: $clientId");

                return false;
            }

            $this->server->getLogger()->debug("[WebSocket Authentication] Authentication successful for client: $clientId");
        }

        if (!$request->isValidWebSocketRequest()) {
            return false;
        }

        return $request->getWebSocketKey() !== null;
    }

    /**
     * Mark a client handshake as complete without running the HTTP upgrade (Swoole path).
     */
    public function markHandshakeComplete(string $clientId): void
    {
        $this->handshakes[$clientId] = true;
    }

    /**
     * Send a custom response to the client
     *
     * @param resource $client The client socket resource
     * @param array<string, mixed> $responseData Response data from middleware
     * @return void
     */
    protected function sendCustomResponse($client, array $responseData): void
    {
        $statusCode = is_int($responseData['status'] ?? null) ? $responseData['status'] : 403;
        $statusText = is_string($responseData['statusText'] ?? null) ? $responseData['statusText'] : 'Forbidden';
        $headers = is_array($responseData['headers'] ?? null) ? $responseData['headers'] : [];
        $body = is_string($responseData['body'] ?? null) ? $responseData['body'] : 'Access denied';

        $response = "HTTP/1.1 $statusCode $statusText\r\n";
        $response .= "Content-Type: text/plain\r\n";

        foreach ($headers as $name => $value) {
            if (is_string($name) && (is_string($value) || is_numeric($value))) {
                $response .= $name . ": " . (string) $value . "\r\n";
            }
        }

        $response .= "\r\n$body";

        fwrite($client, $response);
    }

    /**
     * Check if the origin is allowed
     *
     * @param string $origin
     * @return bool
     */
    protected function isOriginAllowed(string $origin): bool
    {
        if (in_array('*', $this->allowedOrigins)) {
            return true;
        }

        return in_array($origin, $this->allowedOrigins);
    }
}
