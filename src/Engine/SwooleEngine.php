<?php

namespace Sockeon\Sockeon\Engine;

use RuntimeException;
use Sockeon\Sockeon\Config\SwooleEngineConfig;
use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Contracts\Engine\EngineInterface;
use Sockeon\Sockeon\Core\Config;
use Sockeon\Sockeon\Registry\SwooleTableClientRegistry;
use Sockeon\Sockeon\WebSocket\HandshakeRequest;
use Throwable;

class SwooleEngine implements EngineInterface
{
    private Server $server;

    private SwooleEngineConfig $config;

    private SwooleEngineTables $tables;

    private SwooleTableClientRegistry $clientRegistry;

    /**
     * @var object|null Swoole\WebSocket\Server
     */
    private ?object $swooleServer = null;

    /**
     * @var array<int, string>
     */
    private array $pendingClientIds = [];

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
        if (!class_exists(\Swoole\WebSocket\Server::class)) {
            throw new RuntimeException(
                'The Swoole extension is required for the swoole engine. Install ext-swoole or ext-openswoole.'
            );
        }

        $this->server->bootstrapEngineRuntime();
        $this->server->getLogger()->info('[Sockeon Server] Starting Swoole engine...');

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
            'open_http_protocol' => true,
            'open_websocket_protocol' => true,
        ]);

        $server->on('Open', function (\Swoole\WebSocket\Server $server, \Swoole\Http\Request $request): void {
            $this->handleOpen($server, $request);
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
            $this->startWorkerTimers($workerId);
        });

        $this->server->getLogger()->info("[Sockeon Server] Listening on swoole://{$host}:{$port}");
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
        $fd = $this->clientRegistry->getFd($clientId);
        if ($fd !== null) {
            unset($this->pendingClientIds[$fd]);
        }

        $this->clientRegistry->remove($clientId);
    }

    protected function validateIncomingConnection(
        string $clientId,
        HandshakeRequest $handshakeRequest,
        \Swoole\WebSocket\Server $server,
        int $fd,
    ): bool {
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

            if ($result === false || is_array($result)) {
                $server->close($fd);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->server->getLogger()->exception($e, ['context' => 'Swoole handshake validation']);
            $server->close($fd);

            return false;
        }
    }

    protected function handleOpen(\Swoole\WebSocket\Server $server, \Swoole\Http\Request $request): void
    {
        $fd = $request->fd;
        $clientId = $this->clientRegistry->generateClientId();

        if (!$this->validateIncomingConnection($clientId, HandshakeRequest::fromSwooleRequest($request), $server, $fd)) {
            return;
        }

        $maxConnections = min($this->config->getMaxConnection(), $this->server->getMaxConnections());
        if ($this->clientRegistry->count() >= $maxConnections) {
            $this->server->getLogger()->warning('[Sockeon Connection] Connection limit reached, rejecting client', [
                'max_connections' => $maxConnections,
            ]);
            $server->close($fd);

            return;
        }

        $clientIp = $this->getClientIpFromRequest($request);
        if ($clientIp !== null && !$this->server->isConnectionAllowedForIp($clientIp)) {
            $this->server->getLogger()->warning('[Sockeon Connection] Connection rate limit exceeded, rejecting client', [
                'ip' => $clientIp,
            ]);
            $server->close($fd);

            return;
        }

        $workerId = $server->worker_id ?? 0;
        $this->clientRegistry->registerConnection($clientId, $fd, 'ws', $workerId);
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
        unset($this->pendingClientIds[$fd]);
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

    protected function getClientIpFromRequest(\Swoole\Http\Request $request): ?string
    {
        $address = $request->server['remote_addr'] ?? null;

        return is_string($address) && $address !== '' ? $address : null;
    }
}
