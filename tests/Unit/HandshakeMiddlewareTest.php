<?php

use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Contracts\WebSocket\HandshakeMiddleware;
use Sockeon\Sockeon\Core\Middleware;
use Sockeon\Sockeon\WebSocket\HandshakeRequest;

test('server can add handshake middleware', function () {
    /** @var Server $server */
    $server = $this->server; //@phpstan-ignore-line

    $server->addHandshakeMiddleware(TestHandshakeMiddleware::class);

    expect($server->getMiddleware())->toBeInstanceOf(Middleware::class);
});

test('handshake request parses correctly', function () {
    $rawRequest = "GET /chat?token=abc123 HTTP/1.1\r\n"
                  . "Host: localhost:8080\r\n"
                  . "Upgrade: websocket\r\n"
                  . "Connection: Upgrade\r\n"
                  . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
                  . "Origin: http://localhost:3000\r\n"
                  . "Sec-WebSocket-Version: 13\r\n\r\n";

    $request = new HandshakeRequest($rawRequest);

    expect($request->getPath())->toBe('/chat')
        ->and($request->getQueryParam('token'))->toBe('abc123')
        ->and($request->getHeader('Upgrade'))->toBe('websocket')
        ->and($request->getOrigin())->toBe('http://localhost:3000')
        ->and($request->getWebSocketKey())->toBe('dGhlIHNhbXBsZSBub25jZQ==')
        ->and($request->isValidWebSocketRequest())->toBeTrue();
});

test('handshake request from swoole merges query_string into uri', function () {
    $request = (object) [
        'server' => [
            'request_uri' => '/',
            'query_string' => 'auth_token=abc123&city=pokhara',
        ],
        'header' => [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ],
    ];

    $handshake = HandshakeRequest::fromSwooleRequest($request);

    expect($handshake->getPath())->toBe('/')
        ->and($handshake->getQueryParam('auth_token'))->toBe('abc123')
        ->and($handshake->getQueryParam('city'))->toBe('pokhara');
});

class TestHandshakeMiddleware implements HandshakeMiddleware
{
    public function handle(string $clientId, HandshakeRequest $request, callable $next, Server $server): bool|array
    {
        // Simple test middleware that always allows the connection
        return $next();
    }
}
