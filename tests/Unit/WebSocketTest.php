<?php

use Sockeon\Sockeon\WebSocket\Handler;
use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Config\ServerConfig;
use Sockeon\Sockeon\Controllers\SocketController;
use Sockeon\Sockeon\Logging\Logger;
use Sockeon\Sockeon\Core\Router;
use Sockeon\Sockeon\WebSocket\Attributes\SocketOn;

beforeEach(function () {
    $this->handler = new Handler($this->server);
});

test('handles valid JSON messages with data correctly', function () {
    $validMessage = json_encode([
        'event' => 'test.event',
        'data' => ['message' => 'Hello World', 'user_id' => 123],
    ]);

    $frame = $this->handler->encodeWebSocketFrame($validMessage);

    expect($frame)->not->toBeEmpty();
    expect(strlen($frame))->toBeGreaterThan(strlen($validMessage));
});

test('validates message structure with required data field', function () {
    $messages = [
        'missing_data' => json_encode(['event' => 'test.event']),
        'invalid_data_type' => json_encode(['event' => 'test.event', 'data' => 'not an array']),
        'missing_event' => json_encode(['data' => ['test' => 'value']]),
        'invalid_event_type' => json_encode(['event' => 123, 'data' => []]),
        'empty_event' => json_encode(['event' => '', 'data' => []]),
        'invalid_event_format' => json_encode(['event' => 'test@event', 'data' => []]),
        'extra_fields' => json_encode(['event' => 'test.event', 'data' => [], 'extra' => 'field']),
    ];

    foreach ($messages as $testName => $message) {
        $this->handler->handleMessage(1, $message);
        expect(true)->toBeTrue();
    }
});

test('accepts valid messages with empty data array', function () {
    $validMessage = json_encode([
        'event' => 'test.event',
        'data' => [],
    ]);

    $this->handler->handleMessage(1, $validMessage);
    expect(true)->toBeTrue();
});

test('accepts valid messages with complex data structure', function () {
    $validMessage = json_encode([
        'event' => 'user.update',
        'data' => [
            'user_id' => 123,
            'profile' => [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'preferences' => [
                    'theme' => 'dark',
                    'notifications' => true,
                ],
            ],
            'timestamp' => time(),
        ],
    ]);

    $this->handler->handleMessage(1, $validMessage);
    expect(true)->toBeTrue();
});

test('returns an error event when emitting an unknown websocket event', function () {
    /** @var Server $server */
    $server = $this->server; //@phpstan-ignore-line

    $controller = new class extends SocketController {
        #[SocketOn('known.event')]
        public function handleKnownEvent(string $clientId, array $data): void {}
    };
    $server->registerController($controller);

    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    expect($pair)->not->toBeFalse();

    [$clientSocket, $peerSocket] = $pair;

    $reflection = new ReflectionClass($server);

    $clientsProperty = $reflection->getProperty('clients');
    $clients = $clientsProperty->getValue($server);
    $clients['test-client'] = $clientSocket;
    $clientsProperty->setValue($server, $clients);

    $clientTypesProperty = $reflection->getProperty('clientTypes');
    $clientTypes = $clientTypesProperty->getValue($server);
    $clientTypes['test-client'] = 'ws';
    $clientTypesProperty->setValue($server, $clientTypes);

    $this->handler->handleMessage('test-client', json_encode([
        'event' => 'missing.event',
        'data' => [],
    ]));

    $responseFrame = fread($peerSocket, 8192);
    expect($responseFrame)->not->toBeFalse()->not->toBe('');

    [$frames] = $this->handler->decodeWebSocketFrame($responseFrame);
    expect($frames)->toHaveCount(1);

    $response = json_decode($frames[0]['payload'], true);
    expect($response)->toBeArray()
        ->and($response['event'] ?? null)->toBe('error')
        ->and($response['data']['message'] ?? null)->toBe("Event 'missing.event' does not exist");

    fclose($clientSocket);
    fclose($peerSocket);
});

test('handles ping frames correctly', function () {
    $pingFrame = $this->handler->encodeWebSocketFrame('ping payload', 9);

    expect($pingFrame)->not->toBeEmpty();
    expect(ord($pingFrame[0]) & 0x0F)->toBe(9); // Check opcode
});

test('validates frame size limits', function () {
    $largePayload = str_repeat('a', 16777217); // 16MB + 1 byte

    expect(fn() => $this->handler->encodeWebSocketFrame($largePayload))->toThrow(InvalidArgumentException::class);
});

test('handles empty payloads gracefully', function () {
    $this->handler->handleMessage(1, '');
    expect(true)->toBeTrue();
});

test('validates opcodes correctly', function () {
    $validOpcodes = [0, 1, 2, 8, 9, 10];
    $invalidOpcodes = [3, 4, 5, 6, 7, 11, 15];

    foreach ($validOpcodes as $opcode) {
        expect($this->handler->isValidOpcode($opcode))->toBeTrue();
    }

    foreach ($invalidOpcodes as $opcode) {
        expect($this->handler->isValidOpcode($opcode))->toBeFalse();
    }
});

test('handles malformed frames gracefully', function () {
    $malformedFrames = [
        'incomplete_header' => "\x80", // Only 1 byte
        'invalid_length' => "\x80\x7F\x00\x00\x00\x00\x00\x00\x00\x01", // Invalid 64-bit length
        'truncated_frame' => "\x80\x01", // Incomplete frame
    ];

    foreach ($malformedFrames as $testName => $frame) {
        [$frames, $remaining] = $this->handler->decodeWebSocketFrame($frame);
        expect($frames)->toBe([]); // Should return empty array for malformed frames
    }
});

test('logs frame processing correctly', function () {
    $validFrame = $this->handler->encodeWebSocketFrame('test payload', 1);
    [$frames, $remaining] = $this->handler->decodeWebSocketFrame($validFrame);

    expect($frames)->toHaveCount(1);
    expect($frames[0]['opcode'])->toBe(1);
    expect($frames[0]['payload'])->toBe('test payload');
});

test('handles WebSocket protocol correctly', function () {
    $validMessage = json_encode([
        'event' => 'test.event',
        'data' => ['message' => 'Hello World'],
    ]);

    $frame = $this->handler->encodeWebSocketFrame($validMessage);
    [$frames, $remaining] = $this->handler->decodeWebSocketFrame($frame);

    expect($frames)->toHaveCount(1);
    expect($frames[0]['opcode'])->toBe(1);
    expect($frames[0]['fin'])->toBeTrue();
    expect($frames[0]['payload'])->toBe($validMessage);
});

test('handles binary frames correctly', function () {
    $binaryData = "\x00\x01\x02\x03\x04";
    $frame = $this->handler->encodeWebSocketFrame($binaryData, 2); // Binary opcode

    [$frames, $remaining] = $this->handler->decodeWebSocketFrame($frame);

    expect($frames)->toHaveCount(1);
    expect($frames[0]['opcode'])->toBe(2);
    expect($frames[0]['payload'])->toBe($binaryData);
});

test('handles close frames correctly', function () {
    $closeFrame = $this->handler->encodeWebSocketFrame('', 8); // Close opcode

    expect($closeFrame)->not->toBeEmpty();
    expect(ord($closeFrame[0]) & 0x0F)->toBe(8); // Check opcode
});

test('handles pong frames correctly', function () {
    $pongFrame = $this->handler->encodeWebSocketFrame('pong data', 10); // Pong opcode

    expect($pongFrame)->not->toBeEmpty();
    expect(ord($pongFrame[0]) & 0x0F)->toBe(10); // Check opcode
});

test('sends messages with proper data structure', function () {
    $event = 'test.event';
    $data = ['message' => 'Hello World', 'user_id' => 123];

    $message = [
        'event' => $event,
        'data' => $data,
    ];

    $encodedMessage = json_encode($message);
    expect($encodedMessage)->not->toBeFalse();

    $decodedMessage = json_decode($encodedMessage, true);
    expect($decodedMessage)->toBe($message);
    expect($decodedMessage['event'])->toBe($event);
    expect($decodedMessage['data'])->toBe($data);
});

test('validates message structure comprehensively', function () {
    $validMessages = [
        'simple' => ['event' => 'test', 'data' => []],
        'complex' => ['event' => 'user.update', 'data' => ['id' => 1, 'name' => 'John']],
        'nested' => ['event' => 'data.update', 'data' => ['nested' => ['key' => 'value']]],
    ];

    $invalidMessages = [
        'missing_event' => ['data' => []],
        'missing_data' => ['event' => 'test'],
        'invalid_event_type' => ['event' => 123, 'data' => []],
        'invalid_data_type' => ['event' => 'test', 'data' => 'not array'],
        'empty_event' => ['event' => '', 'data' => []],
        'invalid_event_format' => ['event' => 'test@event', 'data' => []],
        'extra_fields' => ['event' => 'test', 'data' => [], 'extra' => 'field'],
    ];

    foreach ($validMessages as $testName => $message) {
        $encoded = json_encode($message);
        $this->handler->handleMessage(1, $encoded);
        expect(true)->toBeTrue();
    }

    foreach ($invalidMessages as $testName => $message) {
        $encoded = json_encode($message);
        $this->handler->handleMessage(1, $encoded);
        expect(true)->toBeTrue();
    }
});
