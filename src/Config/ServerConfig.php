<?php

namespace Sockeon\Sockeon\Config;

use Sockeon\Sockeon\Contracts\LoggerInterface;

class ServerConfig
{
    /**
     * The host the server will bind to.
     */
    public string $host = '0.0.0.0';

    /**
     * The port the server will listen on.
     */
    public int $port = 6001;

    /**
     * Enable or disable debug mode.
     */
    public bool $debug = false;

    /**
     * CORS configuration array.
     * Example:
     * [
     *     'allowed_origins' => ['https://example.com'],
     *     'allowed_methods' => ['GET', 'POST'],
     *     ...
     * ]
     * @var array<string, mixed>
     */
    public array $cors = [];

    /**
     * Optional custom logger. If null, a default logger will be used.
     */
    public ?LoggerInterface $logger = null;

    /**
     * Optional path to queue file.
     */
    public ?string $queueFile = null;

    /**
     * Optional authentication key for securing connections.
     */
    public ?string $authKey = null;
}
