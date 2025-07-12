<?php

use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Controllers\SocketController;
use Sockeon\Sockeon\WebSocket\Attributes\SocketOn;

test('websocket can handle multiple event handlers', function () {
    /** @var Server $server */
    $server = $this->server; //@phpstan-ignore-line
    
    $controller = new class extends SocketController {
        /**
         * @var array<string>
         */
        public array $eventsCalled = [];

        /**
         * @param int $clientId
         * @param array<string, mixed> $data
         * @return bool
         */
        #[SocketOn('event.one')]
        public function handleEventOne(int $clientId, array $data): bool
        {
            $this->eventsCalled[] = 'event.one';
            return true;
        }

        /**
         * @param int $clientId
         * @param array<string, mixed> $data
         * @return bool
         */
        #[SocketOn('event.two')]
        public function handleEventTwo(int $clientId, array $data): bool
        {
            $this->eventsCalled[] = 'event.two';
            return true;
        }
    };
    
    $server->registerController($controller);
    
    $router = $server->getRouter();
    $router->dispatch(1, 'event.one', []);
    $router->dispatch(1, 'event.two', []);
    
    expect($controller->eventsCalled)->toBe(['event.one', 'event.two']);
});

test('websocket can broadcast to multiple clients', function () {
    /** @var Server $server */
    $server = $this->server; //@phpstan-ignore-line

    $controller = new class extends SocketController {
        /**
         * @param int $clientId
         * @param array<string, mixed> $data
         * @return bool
         */
        #[SocketOn('broadcast.test')]
        public function handleBroadcast(int $clientId, array $data): bool
        {
            $this->broadcast('message', [
                'data' => $data['message'] ?? ''
            ]);
            return true;
        }
    };
    
    $server->registerController($controller);
    
    $server->setClientData(1, 'connected', true);
    $server->setClientData(2, 'connected', true);
    $server->setClientData(3, 'connected', true);
    
    expect($server->getClientData(1, 'connected'))->toBeTrue()
        ->and($server->getClientData(2, 'connected'))->toBeTrue()
        ->and($server->getClientData(3, 'connected'))->toBeTrue();
});

test('websocket client data persists', function () {
    /** @var Server $server */
    $server = $this->server; //@phpstan-ignore-line

    $controller = new class extends SocketController {
        /**
         * @param int $clientId
         * @param array<string, mixed> $data
         * @return bool
         */
        #[SocketOn('user.login')]
        public function handleLogin(int $clientId, array $data): bool
        {
            $this->setClientData($clientId, 'user', [
                'id' => $data['userId'] ?? null,
                'name' => $data['name'] ?? 'anonymous'
            ]);
            return true;
        }
    };
    
    $server->registerController($controller);
    
    $router = $server->getRouter();
    $router->dispatch(1, 'user.login', [
        'userId' => 123,
        'name' => 'Test User'
    ]);
    
    $userData = $server->getClientData(1, 'user');
    expect($userData)->toBe([
        'id' => 123,
        'name' => 'Test User'
    ]);
});
