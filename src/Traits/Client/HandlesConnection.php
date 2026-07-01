<?php

/**
 * HandlesConnection trait
 *
 * Manages WebSocket connection establishment and management
 *
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Traits\Client;

use Sockeon\Sockeon\Exception\Client\ConnectionException;
use Sockeon\Sockeon\Exception\Client\HandshakeException;

trait HandlesConnection
{
    /**
     * Connect to the WebSocket server
     *
     * @param array<string, string> $headers Additional HTTP headers for the WebSocket handshake
     * @return bool True on success
     * @throws ConnectionException If connection fails
     * @throws HandshakeException If WebSocket handshake fails
     */
    public function connect(array $headers = []): bool
    {
        $this->disconnect();

        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );

        if ($socket === false) {
            throw new ConnectionException("Could not connect to WebSocket server: " . (is_string($errstr) ? $errstr : 'Unknown error') . " (" . (is_int($errno) ? $errno : 'Unknown code') . ")");
        }

        $this->socket = $socket;

        stream_set_blocking($this->socket, false);
        stream_set_timeout($this->socket, $this->timeout);

        return $this->performHandshake($headers);
    }

    /**
     * Disconnect from the WebSocket server
     *
     * @return bool True on success
     */
    public function disconnect(): bool
    {
        if ($this->socket !== null) {
            if ($this->connected) {
                $this->sendCloseFrame();
            }

            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
            $this->connected = false;
        }

        return true;
    }

    /**
     * Check if the client is connected to the server
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->socket !== null;
    }

    /**
     * Perform WebSocket handshake
     *
     * @param array<string, string> $headers Additional HTTP headers
     * @return bool True on success
     * @throws HandshakeException If handshake fails
     */
    protected function performHandshake(array $headers): bool
    {
        $key = base64_encode(openssl_random_pseudo_bytes(16));

        $defaultHeaders = [
            'Host' => "{$this->host}:{$this->port}",
            'User-Agent' => 'Sockeon PHP WebSocket Client',
            'Connection' => 'Upgrade',
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Key' => $key,
            'Sec-WebSocket-Version' => '13',
            'Origin' => "http://{$this->host}",
        ];

        $headers = array_merge($defaultHeaders, $headers);

        $request = "GET {$this->path} HTTP/1.1\r\n";

        foreach ($headers as $headerName => $headerValue) {
            $request .= "$headerName: $headerValue\r\n";
        }

        $request .= "\r\n";

        if (!is_resource($this->socket)) {
            throw new ConnectionException("Invalid socket resource");
        }

        $bytesWritten = fwrite($this->socket, $request);
        if ($bytesWritten === false || $bytesWritten < strlen($request)) {
            throw new ConnectionException("Failed to send WebSocket handshake request");
        }

        return $this->validateHandshakeResponse($key);
    }

    /**
     * Validate handshake response from server
     *
     * @param string $key The WebSocket key sent in request
     * @return bool True on success
     * @throws HandshakeException If validation fails
     */
    protected function validateHandshakeResponse(string $key): bool
    {
        $response = '';
        $startTime = time();

        while (true) {
            if (time() - $startTime > $this->timeout) {
                throw new HandshakeException("Handshake timed out");
            }

            if (!is_resource($this->socket)) {
                throw new HandshakeException("Invalid socket resource during handshake");
            }

            $buffer = fread($this->socket, 8192);
            if ($buffer === false) {
                throw new HandshakeException("Failed to read from socket during handshake");
            }

            $response .= $buffer;

            if (str_contains($response, "\r\n\r\n")) {
                break;
            }

            usleep(10000);
        }

        if (!preg_match('#Switching Protocols#i', $response)) {
            throw new HandshakeException("WebSocket handshake failed: " . substr($response, 0, 100));
        }

        $expectedKey = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

        if (!preg_match('#Sec-WebSocket-Accept:\s(.+)\r\n#i', $response, $matches) || trim($matches[1]) !== $expectedKey) {
            throw new HandshakeException("Invalid Sec-WebSocket-Accept value");
        }

        $this->connected = true;

        $parts = explode("\r\n\r\n", $response, 2);
        $leftover = $parts[1] ?? '';
        if ($leftover !== '') {
            $this->handleIncomingData($leftover);
        }

        return true;
    }
}
