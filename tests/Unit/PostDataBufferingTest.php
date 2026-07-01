<?php

use Sockeon\Sockeon\Config\ServerConfig;
use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Controllers\SocketController;
use Sockeon\Sockeon\Http\Attributes\HttpRoute;
use Sockeon\Sockeon\Http\Request;
use Sockeon\Sockeon\Http\Response;

test('http post data buffering works correctly', function () {
    $server = $this->server;

    $controller = new class extends SocketController {
        /**
         * @param Request $request
         * @return array<string, mixed>
         */
        #[HttpRoute('POST', '/api/test')]
        public function testPost(Request $request): array
        {
            $body = $request->getBody();
            return [
                'received' => $body,
                'method' => $request->getMethod(),
                'contentType' => $request->getHeader('Content-Type'),
            ];
        }
    };

    $server->registerController($controller);

    $testData = [
        'method' => 'POST',
        'path' => '/api/test',
        'protocol' => 'HTTP/1.1',
        'headers' => [
            'Content-Type' => 'application/json',
            'Content-Length' => '25',
        ],
        'query' => [],
        'body' => '{"name":"John","age":30}',
    ];

    $request = new Request($testData);
    $result = $server->getRouter()->dispatchHttp($request);

    expect($result)->toBeArray()
        ->and($result['received'])->toBe(['name' => 'John', 'age' => 30])
        ->and($result['method'])->toBe('POST');
});

test('http form data parsing works correctly', function () {
    $testData = [
        'method' => 'POST',
        'path' => '/api/form',
        'protocol' => 'HTTP/1.1',
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Content-Length' => '21',
        ],
        'query' => [],
        'body' => 'name=John&age=30&city=NY',
    ];

    $request = new Request($testData);

    expect($request->isFormData())->toBeTrue()
        ->and($request->getBody())->toBe([
            'name' => 'John',
            'age' => '30',
            'city' => 'NY',
        ]);
});

test('incomplete http request detection works', function () {
    $server = $this->server;

    $incompleteData = "POST /api/test HTTP/1.1\r\n";
    $incompleteData .= "Content-Type: application/json\r\n";
    $incompleteData .= "Content-Length: 25\r\n";
    $incompleteData .= "\r\n";
    $incompleteData .= '{"name":"John"';

    $reflection = new ReflectionClass($server);
    $method = $reflection->getMethod('isCompleteHttpRequest');

    $isComplete = $method->invoke($server, $incompleteData);
    expect($isComplete)->toBeFalse();

    $completeData = "POST /api/test HTTP/1.1\r\n";
    $completeData .= "Content-Type: application/json\r\n";
    $completeData .= "Content-Length: 24\r\n";
    $completeData .= "\r\n";
    $completeData .= '{"name":"John","age":30}';

    $isComplete = $method->invoke($server, $completeData);
    expect($isComplete)->toBeTrue();
});
