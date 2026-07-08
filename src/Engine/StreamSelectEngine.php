<?php

namespace Sockeon\Sockeon\Engine;

use RuntimeException;
use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Contracts\Engine\EngineInterface;
use Throwable;

class StreamSelectEngine implements EngineInterface
{
    private Server $server;

    /** @var resource|false */
    private $socket = false;

    private bool $shouldStop = false;

    public function setServer(Server $server): void
    {
        $this->server = $server;
    }

    public function start(): void
    {
        $this->server->bootstrapEngineRuntime();
        $this->server->getPublisher()->start();
        $this->bindSocket();
        $this->loop();
    }

    public function send(string $clientId, string $payload): bool
    {
        $client = $this->server->getClientResource($clientId);
        if ($client === null || $this->server->getClientType($clientId) !== 'ws') {
            return false;
        }

        $result = @fwrite($client, $payload);

        if ($result === false) {
            $this->closeConnection($clientId);

            return false;
        }

        return true;
    }

    public function closeConnection(string $clientId, ?int $fd = null): void
    {
        $this->server->disconnectClient($clientId);
    }

    public function forgetClient(string $clientId): void
    {
        $this->server->forgetClient($clientId);
    }

    public function framesOutboundWebSocket(): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'stream_select';
    }

    private function bindSocket(): void
    {
        $host = $this->server->getHost();
        $port = $this->server->getPort();
        $logger = $this->server->getLogger();

        $logger->debug('[Sockeon Server] Starting server...');

        $context = stream_context_create([
            'socket' => [
                'so_reuseaddr' => 1,
                'so_reuseport' => 1,
                'so_keepalive' => 1,
                'backlog' => 1024,
            ],
        ]);

        $this->socket = stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if (!$this->socket) {
            $errorNumber = is_int($errno) ? $errno : 0;
            $errorString = is_string($errstr) ? $errstr : 'Unknown error';
            throw new RuntimeException("Failed to create socket: $errorString ($errorNumber)");
        }

        stream_set_blocking($this->socket, false);

        if (function_exists('socket_import_stream')) {
            $socketResource = socket_import_stream($this->socket);
            if ($socketResource !== false) {
                socket_set_option($socketResource, SOL_SOCKET, SO_RCVBUF, 262144);
                socket_set_option($socketResource, SOL_SOCKET, SO_SNDBUF, 262144);
                socket_set_option($socketResource, SOL_TCP, TCP_NODELAY, 1);
            }
        }

        $logger->debug("[Sockeon Server] Listening on {$host}:{$port}");
    }

    private function loop(): void
    {
        $this->registerSignalHandlers();

        while (is_resource($this->socket) && ! $this->shouldStop) {
            try {
                $this->server->runEngineLoopHooks();

                /** @var array<resource> $readSockets */
                $readSockets = array_filter(
                    $this->server->getClients(),
                    fn($client) => is_resource($client)
                );
                $readSockets[] = $this->socket;

                $clientCount = count($readSockets) - 1;
                $timeoutSeconds = 0;
                $timeoutMicroseconds = $clientCount === 0 ? 100000 : 10000;

                do {
                    $read = $readSockets;
                    $write = $except = null;
                    $selectResult = @stream_select($read, $write, $except, $timeoutSeconds, $timeoutMicroseconds);
                } while (
                    $selectResult === false
                    && str_contains(error_get_last()['message'] ?? '', 'Interrupted system call')
                );

                if ($selectResult === false) {
                    $this->server->getLogger()->error('[Sockeon Engine] stream_select failed', [
                        'client_count' => $clientCount,
                        'error' => error_get_last()['message'] ?? 'unknown',
                    ]);
                    usleep(10000);

                    continue;
                }

                if ($selectResult > 0) {
                    $this->acceptNewClients($read);
                    $this->handleClientData($read);
                }
            } catch (Throwable $e) {
                $this->server->getLogger()->exception($e, ['context' => 'Main loop']);
                usleep(50000);
            }
        }

        $this->shutdown();
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        $stop = function (): void {
            $this->shouldStop = true;
        };

        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }

    private function shutdown(): void
    {
        foreach ($this->server->getClientIds() as $clientId) {
            $this->server->disconnectClient($clientId);
        }

        if (is_resource($this->socket)) {
            fclose($this->socket);
            $this->socket = false;
        }

        $this->server->getLogger()->debug('[Sockeon Server] Stopped.');
    }

    /**
     * @param array<resource> $read
     */
    private function acceptNewClients(array &$read): void
    {
        if (!in_array($this->socket, $read, true)) {
            return;
        }

        $acceptedCount = 0;
        $remainingCapacity = $this->server->getMaxConnections() - $this->server->getClientCount();
        $maxAcceptPerLoop = min(64, max(0, $remainingCapacity));

        while (($client = @stream_socket_accept($this->socket, 0)) !== false && $acceptedCount < $maxAcceptPerLoop) {
            if (is_resource($client)) {
                stream_set_blocking($client, false);
                $this->server->acceptClientConnection($client);
                $acceptedCount++;
            }
        }

        unset($read[array_search($this->socket, $read, true)]);
    }

    /**
     * @param array<resource> $read
     */
    private function handleClientData(array $read): void
    {
        foreach ($read as $client) {
            $this->server->processReadableClient($client);
        }
    }
}
