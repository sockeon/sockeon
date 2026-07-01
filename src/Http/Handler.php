<?php

/**
 * HttpHandler class
 *
 * Handles HTTP protocol implementation, request parsing and responses
 *
 * Features:
 * - HTTP request parsing
 * - Query parameter extraction
 * - Path normalization
 * - JSON body parsing
 * - Response generation
 *
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Http;

use Sockeon\Sockeon\Config\CorsConfig;
use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Traits\Http\HandlesCors;
use Sockeon\Sockeon\Traits\Http\HandlesHttpLogging;
use Sockeon\Sockeon\Traits\Http\HandlesHttpRequests;
use Throwable;

class Handler
{
    use HandlesCors;
    use HandlesHttpLogging;
    use HandlesHttpRequests;
    /**
     * Reference to the server instance
     * @var Server
     */
    protected Server $server;

    /**
     * Registered HTTP routes
     * @var array<string, array{0: object, 1: string}>
     */
    protected array $routes = [];

    /**
     * CORS configuration
     * @var CorsConfig
     */
    protected CorsConfig $corsConfig;

    /**
     * Constructor
     *
     * @param Server $server The server instance
     * @param CorsConfig $corsConfig Optional CORS configuration
     */
    public function __construct(Server $server, CorsConfig $corsConfig)
    {
        $this->server = $server;
        $this->corsConfig = $corsConfig;
    }

    /**
     * Handle an incoming HTTP request
     *
     * @param string $clientId The client identifier
     * @param resource $client The client socket resource
     * @param string $data The raw HTTP request data
     * @return void
     */
    public function handle(string $clientId, $client, string $data): void
    {
        try {
            $this->debug("Received HTTP request from client #{$clientId}");

            $requestData = $this->parseHttpRequest($data);
            $request = new Request($requestData);

            if ($request->getMethod() === 'OPTIONS') {
                $this->debug("Handling preflight OPTIONS request");
                $response = $this->handleCorsPreflightRequest($request);
            } else {
                $this->debug("Processing standard request");
                $response = $this->processRequest($request);

                $this->debug("Applying CORS headers");
                $response = $this->applyCorsHeaders($request, $response);
            }

            fwrite($client, $response);
        } catch (Throwable $e) {
            $this->server->getLogger()->exception($e, ['clientId' => $clientId, 'context' => 'HttpHandler::handle']);

            try {
                $errorResponse = "HTTP/1.1 500 Internal Server Error\r\n";
                $errorResponse .= "Content-Type: text/plain\r\n";
                $errorResponse .= "Connection: close\r\n\r\n";
                $errorResponse .= "An error occurred while processing your request.";

                fwrite($client, $errorResponse);
            } catch (Throwable $innerEx) {
                $this->server->getLogger()->error("Failed to send error response: " . $innerEx->getMessage());
            }
        }
    }

    /**
     * Handle an HTTP request delivered by the Swoole engine.
     *
     * @param object $swooleRequest Swoole\Http\Request
     */
    public function handleSwoole(string $clientId, object $swooleRequest, \Swoole\Http\Response $swooleResponse): void
    {
        try {
            $requestData = $this->buildRequestDataFromSwoole($swooleRequest);
            $request = new Request($requestData);

            if ($request->getMethod() === 'OPTIONS') {
                $response = $this->handleCorsPreflightRequest($request);
            } else {
                $response = $this->applyCorsHeaders($request, $this->processRequest($request));
            }

            $this->sendSwooleHttpResponse($swooleResponse, $response);
        } catch (Throwable $e) {
            $this->server->getLogger()->exception($e, ['clientId' => $clientId, 'context' => 'HttpHandler::handleSwoole']);
            $swooleResponse->status(500);
            $swooleResponse->end('An error occurred while processing your request.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRequestDataFromSwoole(object $swooleRequest): array
    {
        $method = 'GET';
        if (isset($swooleRequest->server['request_method']) && is_string($swooleRequest->server['request_method'])) {
            $method = $swooleRequest->server['request_method'];
        }

        $path = '/';
        if (isset($swooleRequest->server['request_uri']) && is_string($swooleRequest->server['request_uri'])) {
            $path = parse_url($swooleRequest->server['request_uri'], PHP_URL_PATH) ?: '/';
        }

        $headers = [];
        if (isset($swooleRequest->header) && is_array($swooleRequest->header)) {
            foreach ($swooleRequest->header as $name => $value) {
                if (is_string($name) && (is_string($value) || is_numeric($value))) {
                    $headers[$name] = (string) $value;
                }
            }
        }

        $query = [];
        if (isset($swooleRequest->server['query_string']) && is_string($swooleRequest->server['query_string'])) {
            parse_str($swooleRequest->server['query_string'], $query);
        }

        $body = '';
        if (method_exists($swooleRequest, 'rawContent')) {
            $raw = $swooleRequest->rawContent();
            if (is_string($raw)) {
                $body = $raw;
            }
        }

        return [
            'method' => $method,
            'path' => $path,
            'protocol' => 'HTTP/1.1',
            'headers' => $headers,
            'query' => $query,
            'body' => $body,
        ];
    }

    protected function sendSwooleHttpResponse(\Swoole\Http\Response $swooleResponse, string $rawResponse): void
    {
        $parts = explode("\r\n\r\n", $rawResponse, 2);
        $headerSection = $parts[0] ?? '';
        $body = $parts[1] ?? '';

        $lines = explode("\r\n", $headerSection);
        if (!empty($lines[0]) && preg_match('/HTTP\/\d\.\d\s+(\d+)/', $lines[0], $matches)) {
            $swooleResponse->status((int) $matches[1]);
        }

        for ($i = 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            $value = trim($value);

            // Swoole sets length via end(); Content-Length + Accept-Encoding triggers ERRNO 7105.
            if (strcasecmp($name, 'Content-Length') === 0 || strcasecmp($name, 'Connection') === 0) {
                continue;
            }

            $swooleResponse->header($name, $value);
        }

        $swooleResponse->end($body);
    }
}
