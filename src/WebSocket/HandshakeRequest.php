<?php

/**
 * HandshakeRequest class
 *
 * Represents a WebSocket handshake request with parsed headers and request information.
 * This class provides easy access to handshake request data for middleware processing.
 *
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\WebSocket;

class HandshakeRequest
{
    /**
     * The raw HTTP request data
     * @var string
     */
    protected string $rawRequest;

    /**
     * Parsed HTTP headers
     * @var array<string, string>
     */
    protected array $headers = [];

    /**
     * The request URI (path with query string)
     * @var string|null
     */
    protected ?string $uri = null;

    /**
     * Parsed query parameters
     * @var array<int|string, mixed>
     */
    protected array $queryParams = [];

    /**
     * The request path (without query string)
     * @var string|null
     */
    protected ?string $path = null;

    /**
     * The origin header value
     * @var string|null
     */
    protected ?string $origin = null;

    /**
     * The WebSocket key
     * @var string|null
     */
    protected ?string $webSocketKey = null;

    /**
     * Constructor
     *
     * @param string $rawRequest The raw HTTP handshake request
     */
    public function __construct(string $rawRequest)
    {
        $this->rawRequest = $rawRequest;
        $this->parseRequest();
    }

    /**
     * Build a handshake request from a Swoole HTTP request object.
     */
    public static function fromSwooleRequest(object $request): self
    {
        $uri = '/';
        if (isset($request->server['request_uri']) && is_string($request->server['request_uri'])) {
            $uri = $request->server['request_uri'];
        }

        if (
            isset($request->server['query_string'])
            && is_string($request->server['query_string'])
            && $request->server['query_string'] !== ''
            && !str_contains($uri, '?')
        ) {
            $uri .= '?' . $request->server['query_string'];
        }

        $lines = ["GET {$uri} HTTP/1.1"];

        if (isset($request->header) && is_array($request->header)) {
            foreach ($request->header as $name => $value) {
                if (is_string($name) && (is_string($value) || is_numeric($value))) {
                    $lines[] = $name . ': ' . (string) $value;
                }
            }
        }

        return new self(implode("\r\n", $lines) . "\r\n\r\n");
    }

    /**
     * Get the raw HTTP request data
     *
     * @return string
     */
    public function getRawRequest(): string
    {
        return $this->rawRequest;
    }

    /**
     * Get all parsed headers
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get a specific header value
     *
     * @param string $name Header name (case-insensitive)
     * @return string|null Header value or null if not found
     */
    public function getHeader(string $name): ?string
    {
        $lowerName = strtolower($name);
        foreach ($this->headers as $headerName => $headerValue) {
            if (strtolower($headerName) === $lowerName) {
                return $headerValue;
            }
        }
        return null;
    }

    /**
     * Get the request URI
     *
     * @return string|null
     */
    public function getUri(): ?string
    {
        return $this->uri;
    }

    /**
     * Get the request path (without query string)
     *
     * @return string|null
     */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * Get all query parameters
     *
     * @return array<int|string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * Get a specific query parameter
     *
     * @param string $name Parameter name
     * @return mixed Parameter value or null if not found
     */
    public function getQueryParam(string $name): mixed
    {
        return $this->queryParams[$name] ?? null;
    }

    /**
     * Get the Origin header value
     *
     * @return string|null
     */
    public function getOrigin(): ?string
    {
        return $this->origin;
    }

    /**
     * Get the WebSocket key
     *
     * @return string|null
     */
    public function getWebSocketKey(): ?string
    {
        return $this->webSocketKey;
    }

    /**
     * Check if this is a valid WebSocket handshake request
     *
     * @return bool
     */
    public function isValidWebSocketRequest(): bool
    {
        $connection = $this->getHeader('Connection');
        return $this->getHeader('Upgrade') === 'websocket'
               && $connection !== null
               && str_contains(strtolower($connection), 'upgrade')
               && $this->webSocketKey !== null;
    }

    /**
     * Parse the HTTP request and extract relevant information
     *
     * @return void
     */
    protected function parseRequest(): void
    {
        $lines = explode("\r\n", $this->rawRequest);

        if (!empty($lines[0])) {
            if (preg_match('/GET\s+(.*?)\s+HTTP/i', $lines[0], $matches)) {
                $this->uri = trim($matches[1]);
                $this->parseUri();
            }
        }

        for ($i = 1; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (empty($line)) {
                break;
            }

            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = trim($name);
                $value = trim($value);
                $this->headers[$name] = $value;

                if (strtolower($name) === 'origin') {
                    $this->origin = $value;
                } elseif (strtolower($name) === 'sec-websocket-key') {
                    $this->webSocketKey = $value;
                }
            }
        }
    }

    /**
     * Parse the URI to extract path and query parameters
     *
     * @return void
     */
    protected function parseUri(): void
    {
        if ($this->uri === null) {
            return;
        }

        $parts = parse_url($this->uri);

        if (isset($parts['path'])) {
            $this->path = $parts['path'];
        }

        if (isset($parts['query'])) {
            parse_str($parts['query'], $this->queryParams);
        }
    }
}
