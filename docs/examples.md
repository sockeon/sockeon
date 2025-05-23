# Examples

This document provides examples of using the Socklet library for various use cases.

## Overview of Example Files

The `examples` directory contains three key example files:

1. `example.php` - Basic example with both WebSocket and HTTP capabilities
2. `namespace_example.php` - Demonstrates namespaces and rooms functionality
3. `advanced_http_example.php` - Shows the enhanced HTTP Request and Response features

## Basic WebSocket and HTTP Example

The basic example demonstrates handling both WebSocket events and HTTP requests in a single controller:

```php
<?php

use Xentixar\Socklet\Core\Server;
use Xentixar\Socklet\Core\Contracts\SocketController;
use Xentixar\Socklet\WebSocket\Attributes\SocketOn;
use Xentixar\Socklet\Http\Attributes\HttpRoute;
use Xentixar\Socklet\Http\Request;
use Xentixar\Socklet\Http\Response;

class AppController extends SocketController
{
    #[SocketOn('message.send')]
    public function onMessageSend(int $clientId, array $data)
    {
        $this->broadcast('message.receive', [
            'from' => $this->server->getClientData($clientId, 'user')['name'],
            'message' => $data['message']
        ]);
    }

    #[SocketOn('room.join')]
    public function onRoomJoin(int $clientId, array $data)
    {
        $room = $data['room'] ?? null;
        if ($room) {
            $this->joinRoom($clientId, $room);
            $this->emit($clientId, 'room.joined', [
                'room' => $room
            ]);
        }
    }

    #[HttpRoute('GET', '/api/status')]
    public function getStatus(Request $request): Response
    {
        return Response::json([
            'status' => 'online',
            'time' => date('Y-m-d H:i:s')
        ]);
    }
}

$server = new Server("0.0.0.0", 8000, true);

// Add middleware for user data
$server->addWebSocketMiddleware(function ($clientId, $event, $data, $next) use ($server) {
    $server->setClientData($clientId, 'user', [
        'name' => 'User ' . $clientId,
        'id' => $clientId,
    ]);
    return $next();
});

$server->registerController(new ChatController());
$server->run();
```

### Client Code

```html
<!DOCTYPE html>
<html>
<head>
    <title>Socklet Chat</title>
    <style>
        .message-list {
            height: 400px;
            overflow-y: auto;
            border: 1px solid #ccc;
            padding: 10px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="message-list" id="messages"></div>
    <input type="text" id="messageInput" placeholder="Type your message...">
    <button onclick="sendMessage()">Send</button>
    
    <div>
        <input type="text" id="roomInput" placeholder="Room name">
        <button onclick="joinRoom()">Join Room</button>
    </div>

    <script>
        const socket = new WebSocket('ws://localhost:8000');
        const messages = document.getElementById('messages');
        const messageInput = document.getElementById('messageInput');
        const roomInput = document.getElementById('roomInput');
        let currentRoom = null;

        socket.onopen = () => {
            addMessage('System', 'Connected to server');
        };

        socket.onmessage = (event) => {
            const data = JSON.parse(event.data);
            
            if (data.event === 'message.receive') {
                addMessage(data.data.from, data.data.message);
            } else if (data.event === 'room.joined') {
                currentRoom = data.data.room;
                addMessage('System', `Joined room: ${currentRoom}`);
            }
        };

        function sendMessage() {
            const message = messageInput.value;
            if (message) {
                const data = {
                    message: message
                };
                
                if (currentRoom) {
                    data.room = currentRoom;
                }
                
                socket.send(JSON.stringify({
                    event: 'message.send',
                    data: data
                }));
                
                messageInput.value = '';
            }
        }

        function joinRoom() {
            const room = roomInput.value;
            if (room) {
                socket.send(JSON.stringify({
                    event: 'room.join',
                    data: { room: room }
                }));
                roomInput.value = '';
            }
        }

        function addMessage(from, text) {
            const div = document.createElement('div');
            div.textContent = `${from}: ${text}`;
            messages.appendChild(div);
            messages.scrollTop = messages.scrollHeight;
        }
    </script>
</body>
</html>
```

## Game Server Example

```php
class GameController extends SocketController
{
    protected array $games = [];

    #[SocketOn('game.create')]
    public function onGameCreate(int $clientId, array $data)
    {
        $gameId = uniqid();
        $this->games[$gameId] = [
            'id' => $gameId,
            'players' => [$clientId],
            'state' => 'waiting'
        ];
        
        $this->joinRoom($clientId, "game-{$gameId}");
        $this->emit($clientId, 'game.created', [
            'gameId' => $gameId
        ]);
    }

    #[SocketOn('game.join')]
    public function onGameJoin(int $clientId, array $data)
    {
        $gameId = $data['gameId'] ?? null;
        if ($gameId && isset($this->games[$gameId])) {
            $this->games[$gameId]['players'][] = $clientId;
            $this->joinRoom($clientId, "game-{$gameId}");
            
            $this->broadcast('game.playerJoined', [
                'gameId' => $gameId,
                'playerId' => $clientId
            ], '/', "game-{$gameId}");
        }
    }

    #[SocketOn('game.move')]
    public function onGameMove(int $clientId, array $data)
    {
        $gameId = $data['gameId'] ?? null;
        if ($gameId && isset($this->games[$gameId])) {
            $this->broadcast('game.moveUpdate', [
                'gameId' => $gameId,
                'playerId' => $clientId,
                'move' => $data['move']
            ], '/', "game-{$gameId}");
        }
    }
}
```

## Namespace Example

The namespace example demonstrates how to use namespaces and rooms for role-based WebSocket message broadcasting:

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Xentixar\Socklet\Core\Contracts\SocketController;
use Xentixar\Socklet\Core\Server;
use Xentixar\Socklet\WebSocket\Attributes\SocketOn;

class TestController extends SocketController
{
    /**
     * Handle test.event WebSocket event
     * 
     * Processes incoming messages and broadcasts them to role-specific rooms
     * 
     * @param string $clientId   The ID of the client sending the message
     * @param array  $data       Message data containing:
     *                          - role: (string) User role ('admin' or other)
     *                          - message: (string) Optional message content
     * @return void
     */
    #[SocketOn('test.event')]
    public function sendMessage($clientId, $data)
    {
        if ($data['role'] == 'admin') {
            $this->joinRoom($clientId, 'test.room', '/admin');
            $this->broadcast('test.event', [
                'message' => $data['message'] ?? 'Hello from server',
                'time' => date('H:i:s')
            ], '/admin', 'test.room');
        } else {
            $this->joinRoom($clientId, 'test.room', '/user');
            $this->broadcast('test.event', [
                'message' => $data['message'] ?? 'Hello from server',
                'time' => date('H:i:s')
            ], '/user', 'test.room');
        }
    }
}

// Initialize the WebSocket server
$server = new Server(
    port: 8000,
    debug: true,
);

$server->registerController(
    controller: new TestController(),
);

$server->run();
```

In this example:
- We create role-based namespaces ('/admin' and '/user')
- Messages are broadcast only to clients in the same namespace and room
- Different groups of users can communicate in isolation

## Advanced HTTP Example

The advanced HTTP example demonstrates the enhanced Request and Response features with several endpoints showcasing different capabilities:

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

use Xentixar\Socklet\Core\Server;
use Xentixar\Socklet\Core\Contracts\SocketController;
use Xentixar\Socklet\Http\Attributes\HttpRoute;
use Xentixar\Socklet\Http\Request;
use Xentixar\Socklet\Http\Response;

class AdvancedApiController extends SocketController
{
    private array $products = [
        1 => ['id' => 1, 'name' => 'Laptop', 'price' => 999.99, 'category' => 'electronics'],
        2 => ['id' => 2, 'name' => 'Smartphone', 'price' => 699.99, 'category' => 'electronics'],
        3 => ['id' => 3, 'name' => 'Coffee Maker', 'price' => 89.99, 'category' => 'appliances'],
    ];
    
    #[HttpRoute('GET', '/')]
    public function index(Request $request): Response
    {
        // Demonstrate Request methods
        $clientInfo = [
            'ip' => $request->getIpAddress(),
            'url' => $request->getUrl(),
            'isAjax' => $request->isAjax() ? 'Yes' : 'No',
            'method' => $request->getMethod(),
            'userAgent' => $request->getHeader('User-Agent', 'Unknown')
        ];
        
        // Content negotiation based on Accept header
        if (strpos($request->getHeader('Accept', ''), 'application/json') !== false) {
            return Response::json([
                'api' => 'Socklet HTTP API',
                'client' => $clientInfo,
                'endpoints' => [/* endpoint listing */]
            ]);
        }
        
        // Return HTML by default
        $html = "... HTML content ...";
        return (new Response($html))
            ->setContentType('text/html')
            ->setHeader('X-Demo-Mode', 'enabled');
    }
    
    #[HttpRoute('GET', '/products/{id}')]
    public function getProduct(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        
        if (!isset($this->products[$id])) {
            return Response::notFound([
                'error' => 'Product not found',
                'id' => $id
            ]);
        }
        
        return Response::json($this->products[$id]);
    }
    
    // Additional endpoints showing various response types...
}
```

This example showcases:
- Path parameters: `/products/{id}`
- Query parameters: `GET /filter?category=electronics`
- Content negotiation based on Accept headers
- Various response types (JSON, HTML, errors, downloads)
- Enhanced Request methods (isAjax(), getUrl(), getIpAddress())
- Enhanced Response methods (notFound(), unauthorized(), redirect(), download())

To experiment with this API, run the example and use a tool like curl or Postman to make requests to different endpoints.

For more details about the concepts used in these examples, refer to:
- [Core Concepts](./core-concepts.md)
- [API Reference](./api-reference.md)
