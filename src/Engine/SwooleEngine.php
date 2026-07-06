<?php

namespace Sockeon\Sockeon\Engine;

use RuntimeException;
use Sockeon\Sockeon\Config\SwooleEngineConfig;
use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Contracts\Engine\EngineInterface;
use Sockeon\Sockeon\Core\Config;
use Sockeon\Sockeon\Publisher\RedisPublisher;
use Sockeon\Sockeon\Registry\SwooleTableClientRegistry;
use Sockeon\Sockeon\WebSocket\HandshakeRequest;
use Throwable;

class SwooleEngine implements EngineInterface
{
    private Server $server;

    private SwooleEngineConfig $config;

    private SwooleEngineTables $tables;

    private SwooleTableClientRegistry $clientRegistry;

    private ?\Swoole\WebSocket\Server $swooleServer = null;

    public function __construct(?SwooleEngineConfig $config = null)
    {
        $this->config = $config ?? new SwooleEngineConfig();
        $this->tables = SwooleEngineTables::create($this->config);
        $this->clientRegistry = new SwooleTableClientRegistry($this->tables);
    }

    public function getClientRegistry(): SwooleTableClientRegistry
    {
        return $this->clientRegistry;
    }

    public function setServer(Server $server): void
    {
        $this->server = $server;
    }

    public function getName(): string
    {
        return 'swoole';
    }

    public function framesOutboundWebSocket(): bool
    {
        return false;
    }

    public function start(): void
    {
        ini_set('memory_limit', $this->config->getMemoryLimit());

        if (!class_exists(\Swoole\WebSocket\Server::class)) {
            throw new RuntimeException(
                'The Swoole extension is required for the swoole engine. Install ext-swoole or ext-openswoole.'
            );
        }

        $this->server->bootstrapEngineRuntime();
        $this->server->getLogger()->debug('[Sockeon Server] Starting Swoole engine...');

        $host = $this->server->getHost();
        $port = $this->server->getPort();
        $survivability = $this->server->getSurvivabilityConfig();

        /** @var \Swoole\WebSocket\Server $server */
        $server = new \Swoole\WebSocket\Server($host, $port);
        $this->swooleServer = $server;

        $server->set([
            'worker_num' => $this->config->getWorkerNum(),
            'task_worker_num' => $this->config->getTaskWorkerNum(),
            'max_connection' => min($this->config->getMaxConnection(), $survivability->getMaxConnections()),
            'heartbeat_idle_time' => $survivability->getHeartbeatIdleTime(),
            'heartbeat_check_interval' => $survivability->getHeartbeatCheckInterval(),
            'enable_coroutine' => true,
            'package_max_length' => $this->server->getMaxMessageSize(),
            'socket_buffer_size' => $this->config->getSocketBufferSize(),
            'buffer_output_size' => $this->config->getBufferOutputSize(),
            'open_http_protocol' => true,
            'open_websocket_protocol' => true,
        ]);

        $server->on('handshake', function (\Swoole\Http\Request $request, \Swoole\Http\Response $response) use ($server): bool {
            return $this->handleHandshake($server, $request, $response);
        });

        $server->on('Message', function (\Swoole\WebSocket\Server $server, \Swoole\WebSocket\Frame $frame): void {
            $this->handleMessage($server, $frame);
        });

        $server->on('Close', function (\Swoole\WebSocket\Server $server, int $fd): void {
            $this->handleClose($fd);
        });

        $server->on('Request', function (\Swoole\Http\Request $request, \Swoole\Http\Response $response): void {
            $this->handleHttpRequest($request, $response);
        });

        $server->on('WorkerStart', function (\Swoole\Server $server, int $workerId): void {
            $this->sweepStaleRegistryEntries($server);
            $this->startWorkerTimers($workerId);
        });

        if ($this->config->getTaskWorkerNum() > 0) {
            $server->on('Task', function (\Swoole\Server $server, \Swoole\Server\Task $task): mixed {
                return $this->handleTask($task);
            });

            $server->on('Finish', function (\Swoole\Server $server, int $taskId, mixed $data): void {
                $this->handleTaskFinish($taskId, $data);
            });
        }

        $publisher = $this->server->getPublisher();
        if ($publisher instanceof RedisPublisher) {
            $publisher->registerSwooleSubscriber($server);
        }

        $this->server->getLogger()->debug("[Sockeon Server] Listening on {$host}:{$port}");
        $server->start();
    }

    public function send(string $clientId, string $payload): bool
    {
        if ($this->swooleServer === null) {
            return false;
        }

        $fd = $this->clientRegistry->getFd($clientId);
        if ($fd === null) {
            return false;
        }

        if (!$this->swooleServer->isEstablished($fd)) {
            return false;
        }

        /** @var \Swoole\WebSocket\Server $server */
        $server = $this->swooleServer;

        return $server->push($fd, $payload, 1) !== false;
    }

    public function closeConnection(string $clientId, ?int $fd = null): void
    {
        if ($this->swooleServer === null) {
            return;
        }

        $fd ??= $this->clientRegistry->getFd($clientId);
        if ($fd === null) {
            return;
        }

        if ($this->swooleServer->isEstablished($fd)) {
            $this->swooleServer->close($fd);
        }
    }

    public function forgetClient(string $clientId): void
    {
        $this->clientRegistry->remove($clientId);
    }

    protected function handleHandshake(
        \Swoole\WebSocket\Server $server,
        \Swoole\Http\Request $request,
        \Swoole\Http\Response $response,
    ): bool {
        $fd = $request->fd;
        $clientId = $this->clientRegistry->generateClientId();
        $handshakeRequest = HandshakeRequest::fromSwooleRequest($request);

        try {
            /** @var bool|array<string, mixed> $result */
            $result = $this->server->getMiddleware()->runHandshakeStack(
                $clientId,
                $handshakeRequest,
                function (string $clientId, HandshakeRequest $request): bool {
                    return $this->server->getWsHandler()->validateHandshakeRequest($clientId, $request);
                },
                $this->server
            );

            if ($result === false) {
                $this->rejectHandshake($server, $response, $fd, []);

                return false;
            }

            if (is_array($result)) {
                $this->rejectHandshake($server, $response, $fd, $result);

                return false;
            }
        } catch (Throwable $e) {
            $this->server->getLogger()->exception($e, ['context' => 'Swoole handshake validation']);
            $this->rejectHandshake($server, $response, $fd, [
                'status' => 500,
                'statusText' => 'Internal Server Error',
                'body' => 'An error occurred while processing your request.',
            ]);

            return false;
        }

        $maxConnections = min($this->config->getMaxConnection(), $this->server->getMaxConnections());
        if (!$this->clientRegistry->hasTableCapacity() || $this->clientRegistry->count() >= $maxConnections) {
            $this->server->getLogger()->warning('[Sockeon Connection] Connection limit reached, rejecting client', [
                'max_connections' => $maxConnections,
            ]);
            $this->rejectHandshake($server, $response, $fd, [
                'status' => 503,
                'statusText' => 'Service Unavailable',
                'body' => 'Connection limit reached',
            ]);

            return false;
        }

        $clientIp = $this->getClientIpFromRequest($request);
        if ($clientIp !== null && !$this->server->isConnectionAllowedForIp($clientIp)) {
            $this->server->getLogger()->warning('[Sockeon Connection] Connection rate limit exceeded, rejecting client', [
                'ip' => $clientIp,
            ]);
            $this->rejectHandshake($server, $response, $fd, [
                'status' => 429,
                'statusText' => 'Too Many Requests',
                'body' => 'Connection rate limit exceeded',
            ]);

            return false;
        }

        if (!$this->completeSwooleHandshake($handshakeRequest, $response)) {
            $server->close($fd);

            return false;
        }

        $this->completeWebSocketOpen($server, $request, $clientId, $fd, $clientIp);

        return true;
    }

    /**
     * @param array<string, mixed> $responseData
     */
    protected function rejectHandshake(
        \Swoole\WebSocket\Server $server,
        \Swoole\Http\Response $response,
        int $fd,
        array $responseData,
    ): void {
        $statusCode = is_int($responseData['status'] ?? null) ? $responseData['status'] : 403;
        $body = is_string($responseData['body'] ?? null) ? $responseData['body'] : 'Access denied';
        $headers = is_array($responseData['headers'] ?? null) ? $responseData['headers'] : [];

        $response->status($statusCode);
        $response->header('Content-Type', 'text/plain');

        foreach ($headers as $name => $value) {
            if (is_string($name) && (is_string($value) || is_numeric($value))) {
                $response->header($name, (string) $value);
            }
        }

        $response->end($body);
        $server->close($fd);
    }

    protected function completeSwooleHandshake(HandshakeRequest $handshakeRequest, \Swoole\Http\Response $response): bool
    {
        $webSocketKey = $handshakeRequest->getWebSocketKey();
        if ($webSocketKey === null) {
            return false;
        }

        $acceptKey = base64_encode(sha1($webSocketKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $response->header('Upgrade', 'websocket');
        $response->header('Connection', 'Upgrade');
        $response->header('Sec-WebSocket-Accept', $acceptKey);
        $response->header('Sec-WebSocket-Version', '13');

        $origin = $handshakeRequest->getOrigin();
        if ($origin !== null) {
            $response->header('Access-Control-Allow-Origin', $origin);
        }

        $protocol = $handshakeRequest->getHeader('Sec-WebSocket-Protocol');
        if ($protocol !== null) {
            $response->header('Sec-WebSocket-Protocol', $protocol);
        }

        $response->status(101);
        $response->end();

        return true;
    }

    protected function completeWebSocketOpen(
        \Swoole\WebSocket\Server $server,
        \Swoole\Http\Request $request,
        string $clientId,
        int $fd,
        ?string $clientIp,
    ): void {
        $workerId = $server->worker_id ?? 0;

        if (!$this->clientRegistry->registerConnection($clientId, $fd, 'ws', $workerId)) {
            $this->server->getLogger()->warning('[Sockeon Connection] Client registry full, rejecting connection', [
                'fd' => $fd,
                'registry_count' => $this->clientRegistry->count(),
            ]);
            $server->close($fd);

            return;
        }

        $this->server->registerSwooleClient($clientId, $fd, 'ws', $clientIp);
        $this->server->getWsHandler()->markHandshakeComplete($clientId);

        $this->server->getLogger()->debug("[Sockeon Connection] WebSocket client connected: $clientId (fd: $fd)");

        $this->runInCoroutine(function () use ($clientId): void {
            try {
                $this->server->getRouter()->dispatchSpecialEvent($clientId, 'connect');
            } catch (Throwable $e) {
                $this->server->getLogger()->exception($e, ['clientId' => $clientId, 'context' => 'connect event']);
            }
        });
    }

    protected function handleMessage(\Swoole\WebSocket\Server $server, \Swoole\WebSocket\Frame $frame): void
    {
        $clientId = $this->clientRegistry->getClientIdByResource($frame->fd);
        if ($clientId === null) {
            $server->close($frame->fd);

            return;
        }

        try {
            if ($frame->opcode === 8) {
                $this->closeConnection($clientId, $frame->fd);

                return;
            }

            if ($frame->opcode === 9) {
                $server->push($frame->fd, $frame->data, 10);

                return;
            }

            if ($frame->opcode === 10) {
                return;
            }

            if ($frame->opcode === 1 || $frame->opcode === 2) {
                $payload = $frame->data;
                if (!is_string($payload) || $payload === '') {
                    return;
                }

                $this->runInCoroutine(function () use ($clientId, $payload): void {
                    $this->server->getWsHandler()->handleMessage($clientId, $payload);
                });
            }
        } catch (Throwable $e) {
            $this->server->getLogger()->exception($e, [
                'clientId' => $clientId,
                'context' => 'SwooleEngine::handleMessage',
            ]);
            $this->closeConnection($clientId, $frame->fd);
        }
    }

    protected function handleClose(int $fd): void
    {
        $clientId = $this->clientRegistry->getClientIdByResource($fd);
        if ($clientId === null) {
            return;
        }

        $this->server->finalizeSwooleClientDisconnect($clientId);
        $this->clientRegistry->remove($clientId);
    }

    protected function handleHttpRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response): void
    {
        $clientId = $this->clientRegistry->generateClientId();

        try {
            $this->runInCoroutine(function () use ($clientId, $request, $response): void {
                $this->server->getHttpHandler()->handleSwoole($clientId, $request, $response);
            });
        } catch (Throwable $e) {
            $this->server->getLogger()->exception($e, ['clientId' => $clientId, 'context' => 'Swoole HTTP']);
            $response->status(500);
            $response->end('An error occurred while processing your request.');
        }
    }

    protected function sweepStaleRegistryEntries(\Swoole\Server $server): void
    {
        $removed = $this->clientRegistry->removeStaleConnections(
            fn(int $fd): bool => (bool) $server->exist($fd),
        );

        if ($removed > 0) {
            $this->server->getLogger()->warning('[Sockeon Connection] Removed stale registry entries after worker start', [
                'removed' => $removed,
                'registry_count' => $this->clientRegistry->count(),
            ]);
        }
    }

    protected function startWorkerTimers(int $workerId): void
    {
        if ($workerId !== 0 || !class_exists(\Swoole\Timer::class)) {
            return;
        }

        \Swoole\Timer::tick(100, function (): void {
            $this->server->processQueueFromFile();
        });
    }

    protected function runInCoroutine(callable $callback): void
    {
        if ($this->config->useCoroutineDispatch() && class_exists(\Swoole\Coroutine::class)) {
            \Swoole\Coroutine::create($callback);

            return;
        }

        $callback();
    }

    protected function handleTask(\Swoole\Server\Task $task): mixed
    {
        $data = $task->data;

        if (! is_array($data)) {
            return false;
        }

        $type = $data['type'] ?? null;

        if (! is_string($type) || $type === '') {
            return false;
        }

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

        return $this->server->processSwooleTask($type, $payload);
    }

    protected function handleTaskFinish(int $taskId, mixed $data): void
    {
        if ($data === false) {
            $this->server->getLogger()->warning('[Sockeon Task] Task failed', ['task_id' => $taskId]);
        }
    }

    protected function getClientIpFromRequest(\Swoole\Http\Request $request): ?string
    {
        $address = $request->server['remote_addr'] ?? null;

        return is_string($address) && $address !== '' ? $address : null;
    }
}
