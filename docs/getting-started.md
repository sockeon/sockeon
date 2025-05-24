# Getting Started with Sockeon

## Installation

You can install Sockeon using Composer:

```bash
composer require sockeon/sockeon
```

## Basic Usage

### 1. Create a Server

```php
use Sockeon\Sockeon\Core\Server;

// Initialize server on localhost:8000
$server = new Server("0.0.0.0", 8000);

// Start the server
$server->run();
```

### 2. Create a Controller

```php
use Sockeon\Sockeon\Core\Contracts\SocketController;
use Sockeon\Sockeon\WebSocket\Attributes\SocketOn;
use Sockeon\Sockeon\Http\Attributes\HttpRoute;
use Sockeon\Sockeon\Http\Request;
use Sockeon\Sockeon\Http\Response;

class ChatController extends SocketController
{
    #[SocketOn('message.send')]
    public function onMessage(int $clientId, array $data)
    {
        // Handle incoming message
        $this->broadcast('message.receive', [
            'from' => $clientId,
            'message' => $data['message']
        ]);
    }

    #[HttpRoute('GET', '/status')]
    public function getStatus(Request $request)
    {
        return Response::json([
            'status' => 'online',
            'time' => date('Y-m-d H:i:s')
        ]);
    }
}
```

### 3. Register the Controller

```php
$server->registerController(new ChatController());
```

### 4. Add Middleware (Optional)

```php
// Add WebSocket middleware
$server->addWebSocketMiddleware(function ($clientId, $event, $data, $next) {
    echo "WebSocket Event: $event from client $clientId\n";
    return $next();
});

// Add HTTP middleware
$server->addHttpMiddleware(function (Request $request, $next) {
    echo "HTTP Request: {$request->getMethod()} {$request->getPath()}\n";
    return $next();
});
```

## WebSocket Client Example

```javascript
const socket = new WebSocket('ws://localhost:8000');

socket.onopen = () => {
    console.log('Connected to server');
    
    // Send a message
    socket.send(JSON.stringify({
        event: 'message.send',
        data: {
            message: 'Hello, World!'
        }
    }));
};

socket.onmessage = (event) => {
    const data = JSON.parse(event.data);
    console.log('Received:', data);
};
```

## Using Rooms

```php
#[SocketOn('room.join')]
public function onJoinRoom(int $clientId, array $data)
{
    $room = $data['room'] ?? null;
    if ($room) {
        $this->joinRoom($clientId, $room);
        $this->emit($clientId, 'room.joined', [
            'room' => $room
        ]);
    }
}

#[SocketOn('message.room')]
public function onRoomMessage(int $clientId, array $data)
{
    $room = $data['room'] ?? null;
    if ($room) {
        $this->broadcast('message.receive', [
            'from' => $clientId,
            'message' => $data['message']
        ], '/', $room);
    }
}
```

For more detailed information, check out the other documentation sections:
- [Core Concepts](./core-concepts.md)
- [API Reference](./api-reference.md)
- [Examples](./examples.md)
- [Advanced Topics](./advanced-topics.md)
