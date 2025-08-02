<?php

namespace Sockeon\Sockeon\Traits\Server;

use Sockeon\Sockeon\Config\ServerConfig;
use Sockeon\Sockeon\Core\Config;
use Sockeon\Sockeon\Core\Middleware;
use Sockeon\Sockeon\Core\NamespaceManager;
use Sockeon\Sockeon\Core\Router;
use Sockeon\Sockeon\Http\Handler as HttpHandler;
use Sockeon\Sockeon\WebSocket\Handler as WebSocketHandler;
use Sockeon\Sockeon\Logging\Logger;
use Sockeon\Sockeon\Logging\LogLevel;

trait HandlesConfiguration
{
    protected function applyServerConfig(ServerConfig $config): void
    {
        $this->host = $config->host;
        $this->port = $config->port;
        $this->isDebug = $config->debug;

        Config::init();

        if ($config->queueFile) {
            Config::setQueueFile($config->queueFile);
        }

        if ($config->authKey !== null) {
            Config::setAuthKey($config->authKey);
        }

        $this->rateLimitConfig = $config->rateLimitConfig;

        $this->logger = $config->logger ?? new Logger(
            minLogLevel: $this->isDebug ? LogLevel::DEBUG : LogLevel::INFO,
            logToConsole: true,
            logToFile: false,
            logDirectory: null,
            separateLogFiles: false
        );
    }

    protected function initializeCoreComponents(ServerConfig $config): void
    {
        $this->router = new Router();
        $this->wsHandler = new WebSocketHandler($this, $this->resolveAllowedOrigins($config->cors));
        $this->httpHandler = new HttpHandler($this, $config->cors);
        $this->namespaceManager = new NamespaceManager();
        $this->middleware = new Middleware();

        if ($this->rateLimitConfig && $this->rateLimitConfig->isEnabled()) {
            $this->middleware->addHttpMiddleware(\Sockeon\Sockeon\Http\Middleware\HttpRateLimitMiddleware::class);
            $this->middleware->addWebSocketMiddleware(\Sockeon\Sockeon\WebSocket\Middleware\WebSocketRateLimitMiddleware::class);
        }
    }

    /**
     * @param array<string, mixed> $cors
     * @return array<int, string>
     */
    protected function resolveAllowedOrigins(array $cors): array
    {
        $origins = [];

        if (isset($cors['allowed_origins']) && is_array($cors['allowed_origins'])) {
            foreach ($cors['allowed_origins'] as $origin) {
                if (is_string($origin)) {
                    $origins[] = $origin;
                }
            }
        }

        return $origins ?: ['*'];
    }
}
