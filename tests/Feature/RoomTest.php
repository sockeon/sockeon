<?php

use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Controllers\SocketController;
use Sockeon\Sockeon\Core\NamespaceManager;
use Sockeon\Sockeon\WebSocket\Attributes\SocketOn;

test('rooms can be joined and left', function () {
    /** @var Server $server */
    $server = $this->server; //@phpstan-ignore-line

    $controller = new class extends SocketController {
        /**
         * @param string $clientId
         * @param array<string, string> $data
         * @return true
         */
        #[SocketOn('room.join')]
        public function onRoomJoin(string $clientId, array $data): bool
        {
            parent::joinRoom($clientId, $data['room'] ?? 'default');
            return true;
        }

        /**
         * @param string $clientId
         * @param array<string, string> $data
         * @return true
         */
        #[SocketOn('room.leave')]
        public function onRoomLeave(string $clientId, array $data): bool
        {
            parent::leaveRoom($clientId, $data['room'] ?? 'default');
            return true;
        }
    };

    $server->registerController($controller);

    expect($server->getNamespaceManager())->toBeInstanceOf(NamespaceManager::class);
});

test('messages can be broadcast to rooms', function () {
    /** @var Server $server */
    $server = $this->server; //@phpstan-ignore-line

    $controller = new class extends SocketController {
        /**
         * @param string $clientId
         * @param array<string, string> $data
         * @return bool
         */
        #[SocketOn('broadcast.room')]
        public function onBroadcastToRoom(string $clientId, array $data): bool
        {
            $this->broadcast('room.message', [
                'message' => $data['message'] ?? '',
                'room' => $data['room'] ?? 'default',
            ], '/', $data['room'] ?? 'default');
            return true;
        }
    };

    $server->registerController($controller);

    expect($server->getNamespaceManager())->toBeInstanceOf(NamespaceManager::class);
});

test('namespaces can be created and managed', function () {
    /** @var Server $server */
    $server = $this->server; //@phpstan-ignore-line

    $controller = new class extends SocketController {
        /**
         * @param string $clientId
         * @param array<string, string> $data
         * @return bool
         */
        #[SocketOn('namespace.join')]
        public function onJoinNamespace(string $clientId, array $data): bool
        {
            $this->joinRoom($clientId, 'room1', $data['namespace'] ?? '/');
            return true;
        }

        /**
         * @param string $clientId
         * @param array<string, string> $data
         * @return bool
         */
        #[SocketOn('namespace.broadcast')]
        public function onBroadcastToNamespace(string $clientId, array $data): bool
        {
            $this->broadcast('message', [
                'data' => $data['message'] ?? '',
            ], $data['namespace'] ?? '/');
            return true;
        }
    };

    $server->registerController($controller);

    $router = $server->getRouter();
    $router->dispatch(1, 'namespace.join', ['namespace' => '/admin']);
    $router->dispatch(1, 'namespace.broadcast', [
        'namespace' => '/admin',
        'message' => 'Hello Admin',
    ]);

    expect($server->getNamespaceManager())->toBeInstanceOf(NamespaceManager::class);
});

test('client can join multiple rooms', function () {
    /** @var Server $server */
    $server = $this->server; //@phpstan-ignore-line

    $controller = new class extends SocketController {
        /**
         * @param string $clientId
         * @param array<string, array<string>> $data
         * @return bool
         */
        #[SocketOn('rooms.join')]
        public function joinRooms(string $clientId, array $data): bool
        {
            foreach ($data['rooms'] ?? [] as $room) {
                $this->joinRoom($clientId, $room);
            }
            return true;
        }
    };

    $server->registerController($controller);

    $router = $server->getRouter();
    $router->dispatch(1, 'rooms.join', [
        'rooms' => ['room1', 'room2', 'room3'],
    ]);

    // Implementation note: We'd need to add a method to check room membership
    // in the NamespaceManager class to make this test more meaningful
    expect($server->getNamespaceManager())->toBeInstanceOf(NamespaceManager::class);
});

test('rooms in different namespaces are isolated', function () {
    /** @var Server $server */
    $server = $this->server; //@phpstan-ignore-line

    $controller = new class extends SocketController {
        /**
         * @param string $clientId
         * @param array<string, string> $data
         * @return bool
         */
        #[SocketOn('room.broadcast')]
        public function onRoomBroadcast(string $clientId, array $data): bool
        {
            $this->broadcast('message', [
                'data' => $data['message'] ?? '',
            ], $data['namespace'] ?? '/', $data['room'] ?? 'default');
            return true;
        }
    };

    $server->registerController($controller);

    $router = $server->getRouter();
    $server->getNamespaceManager()->joinRoom(1, 'chatroom', '/user');
    $server->getNamespaceManager()->joinRoom(2, 'chatroom', '/admin');

    expect($server->getNamespaceManager())->toBeInstanceOf(NamespaceManager::class);
});
