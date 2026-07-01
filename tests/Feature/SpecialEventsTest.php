<?php

use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Controllers\SocketController;
use Sockeon\Sockeon\Core\Router;
use Sockeon\Sockeon\WebSocket\Attributes\SocketOn;
use Sockeon\Sockeon\WebSocket\Attributes\OnConnect;
use Sockeon\Sockeon\WebSocket\Attributes\OnDisconnect;

class TestSpecialEventsController extends SocketController
{
    public array $events = [];

    /**
     * Handler for when a client connects
     * @param string $clientId
     * @return void
     */
    #[OnConnect]
    public function handleConnect(string $clientId): void
    {
        $this->events[] = "connect:$clientId";

        $this->emit($clientId, 'welcome', [
            'message' => 'Welcome to the server!',
            'clientId' => $clientId,
        ]);
    }

    /**
     * Handler for when a client disconnects
     * @param string $clientId
     * @return void
     */
    #[OnDisconnect]
    public function handleDisconnect(string $clientId): void
    {
        $this->events[] = "disconnect:$clientId";

        $this->broadcast('user.left', [
            'clientId' => $clientId,
            'message' => "Client $clientId has left the server",
        ]);
    }

    /**
     * Regular event handler
     * @param string $clientId
     * @param array<string, mixed> $data
     * @return void
     */
    #[SocketOn('message.send')]
    public function handleMessage(string $clientId, array $data): void
    {
        $this->events[] = "message:$clientId";

        $this->broadcast('message.receive', [
            'message' => $data['message'] ?? '',
            'from' => $clientId,
        ]);
    }
}

test('special events are registered correctly', function () {
    /** @var Server $server */
    $server = $this->server; //@phpstan-ignore-line

    $controller = new TestSpecialEventsController();

    $server->registerController($controller);

    $router = $server->getRouter();
    expect($router)->toBeInstanceOf(Router::class);

    $reflection = new ReflectionClass($router);
    $specialEventHandlersProperty = $reflection->getProperty('specialEventHandlers');
    $specialEventHandlers = $specialEventHandlersProperty->getValue($router);

    expect($specialEventHandlers['connect'])->toHaveCount(1);
    expect($specialEventHandlers['disconnect'])->toHaveCount(1);
    expect($specialEventHandlers['connect'][0][1])->toBe('handleConnect');
    expect($specialEventHandlers['disconnect'][0][1])->toBe('handleDisconnect');
});

test('special events can be dispatched', function () {
    /** @var Server $server */
    $server = $this->server; //@phpstan-ignore-line

    $controller = new TestSpecialEventsController();
    $server->registerController($controller);

    $router = $server->getRouter();

    $clientId = 123;
    $router->dispatchSpecialEvent($clientId, 'connect');

    expect($controller->events)->toContain("connect:$clientId");

    $router->dispatchSpecialEvent($clientId, 'disconnect');

    expect($controller->events)->toContain("disconnect:$clientId");
});

test('multiple controllers can have special event handlers', function () {
    /** @var Server $server */
    $server = $this->server; //@phpstan-ignore-line

    $controller1 = new TestSpecialEventsController();
    $controller2 = new TestSpecialEventsController();

    $server->registerController($controller1);
    $server->registerController($controller2);

    $router = $server->getRouter();

    $reflection = new ReflectionClass($router);
    $specialEventHandlersProperty = $reflection->getProperty('specialEventHandlers');
    $specialEventHandlers = $specialEventHandlersProperty->getValue($router);

    expect($specialEventHandlers['connect'])->toHaveCount(2);
    expect($specialEventHandlers['disconnect'])->toHaveCount(2);

    $clientId = 456;
    $router->dispatchSpecialEvent($clientId, 'connect');

    expect($controller1->events)->toContain("connect:$clientId");
    expect($controller2->events)->toContain("connect:$clientId");
});
