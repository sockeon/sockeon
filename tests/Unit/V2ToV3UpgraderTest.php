<?php

use Sockeon\Sockeon\Upgrade\V2ToV3Upgrader;

test('upgrades server and controller API calls', function () {
    $source = <<<'PHP'
        <?php

        class ChatController extends SocketController
        {
            public function handle(string $clientId, array $data): void
            {
                $server = $this->getServer();
                $server->send($clientId, 'chat.message', $data);
                $server->sendToClient($clientId, 'raw');

                $this->broadcastToRoomClients('chat.message', $data, $data['room'], '/chat');
                $this->broadcastToNamespaceClients('announcement', $data, '/admin');
                $this->broadcastToAll('ping', []);
                $this->broadcastTo([$clientId], 'direct', $data);
                $this->broadcastExcept([$clientId], 'others', $data);
                $this->moveClientToNamespace($clientId, '/chat');
                $this->getAllClients();
                $this->disconnectClient($clientId);
                $this->isClientConnected($clientId);
                $this->getClientIpAddress($clientId);
                $this->setClientData($clientId, 'name', 'Ada');
                $name = $this->getClientData($clientId, 'name');
                $bag = $this->getClientData($clientId);
                $this->hasClientData($clientId, 'name');

                $this->getServer()->getEngine()->send($clientId, 'payload');
            }
        }
        PHP;

    $upgrader = new V2ToV3Upgrader();
    $result = $upgrader->upgradePhp($source);

    expect($result['content'])
        ->toContain('$server->emit(')
        ->toContain('$server->sendRaw(')
        ->toContain("->broadcastToRoom('chat.message', \$data, '/chat', \$data['room'])")
        ->toContain("->broadcastToNamespace('announcement', \$data, '/admin')")
        ->toContain("->broadcast('ping', [])")
        ->toContain("->broadcastTo('direct', \$data, [\$clientId])")
        ->toContain("->broadcastExcept('others', \$data, [\$clientId])")
        ->toContain("->joinNamespace(\$clientId, '/chat')")
        ->toContain('->getClientIds()')
        ->toContain('$this->disconnect(')
        ->toContain('$this->isConnected(')
        ->toContain('$this->getClientIp(')
        ->toContain('$this->putData(')
        ->toContain("\$this->data(\$clientId, 'name')")
        ->toContain('$this->allData($clientId)')
        ->toContain('$this->hasData(')
        ->toContain("getEngine()->send(\$clientId, 'payload')");
});

test('upgrades sockeon config defaults', function () {
    $source = <<<'PHP'
        <?php

        return [
            'host' => '0.0.0.0',
            'port' => 6001,
            'debug' => false,
            'rate_limit' => [
                'enabled' => true,
                'maxHttpRequestsPerIp' => 100,
            ],
        ];
        PHP;

    $upgrader = new V2ToV3Upgrader();
    $result = $upgrader->upgradeConfig($source, 'config/sockeon.php');

    expect($result['content'])
        ->toContain("'engine' => 'stream_select'")
        ->toContain("'survivability'")
        ->toContain('maxGlobalConnections')
        ->toContain('maxConnectionsPerIp');
});

test('call argument parser splits nested arrays', function () {
    $args = Sockeon\Sockeon\Upgrade\CallArgumentParser::split("['a'], 'event', ['k' => 'v']");

    expect($args)->toBe(["['a']", "'event'", "['k' => 'v']"]);
});
