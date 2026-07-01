<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Sockeon\Sockeon\Config\ServerConfig;
use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Logging\Logger;
use Sockeon\Sockeon\Logging\LogLevel;

$port = isset($argv[1]) ? (int) $argv[1] : 0;
$maxConnections = isset($argv[2]) ? (int) $argv[2] : 10_000;
$engine = $argv[3] ?? 'stream_select';

if ($port <= 0) {
    fwrite(STDERR, "Usage: run_server.php <port> [max_connections] [engine]\n");
    exit(1);
}

$logger = new Logger(
    minLogLevel: LogLevel::INFO,
    logToConsole: true,
    logToFile: false,
);

$config = new ServerConfig([
    'engine' => $engine,
    'survivability' => ['max_connections' => $maxConnections],
]);
$config->setHost('127.0.0.1');
$config->setPort($port);
$config->setLogger($logger);

(new Server($config))->run();
