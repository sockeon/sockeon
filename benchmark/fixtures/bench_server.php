<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Sockeon\Sockeon\Config\ServerConfig;
use Sockeon\Sockeon\Connection\Server;
use Sockeon\Sockeon\Contracts\WebSocket\WebsocketMiddleware;
use Sockeon\Sockeon\Controllers\SocketController;
use Sockeon\Sockeon\Http\Attributes\HttpRoute;
use Sockeon\Sockeon\Http\Request;
use Sockeon\Sockeon\Http\Response;
use Sockeon\Sockeon\Logging\Logger;
use Sockeon\Sockeon\Logging\LogLevel;
use Sockeon\Sockeon\Validation\Validator;
use Sockeon\Sockeon\WebSocket\Attributes\OnConnect;
use Sockeon\Sockeon\WebSocket\Attributes\SocketOn;

final class BenchEchoController extends SocketController
{
    /**
     * @param array<string, mixed> $data
     */
    #[SocketOn('ping')]
    public function onPing(string $clientId, array $data): void
    {
        $this->emit($clientId, 'pong', ['seq' => $data['seq'] ?? 0]);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[SocketOn('announce')]
    public function onAnnounce(string $clientId, array $data): void
    {
        $room = is_string($data['room'] ?? null) && $data['room'] !== '' ? $data['room'] : 'bench';

        $this->broadcast('announcement', [
            'seq' => $data['seq'] ?? 0,
            'from' => $clientId,
        ], '/', $room);
    }
}

final class BenchHandledController extends SocketController
{
    /**
     * @param array<string, mixed> $data
     */
    #[SocketOn('validated_ping')]
    public function onValidatedPing(string $clientId, array $data): void
    {
        (new Validator())->validate($data, [
            'seq' => 'required|integer',
            'token' => 'required|string|min:8',
        ]);

        $this->emit($clientId, 'validated_pong', ['seq' => $data['seq']]);
    }
}

final class BenchDbController extends SocketController
{
    private static ?\PDO $pdo = null;

    /**
     * @param array<string, mixed> $data
     */
    #[SocketOn('db_ping')]
    public function onDbPing(string $clientId, array $data): void
    {
        $seq = (int) ($data['seq'] ?? 0);
        self::connection()->query('SELECT 1')->fetchColumn();

        $this->emit($clientId, 'db_pong', ['seq' => $seq]);
    }

    private static function connection(): \PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new \PDO('sqlite::memory:');
            self::$pdo->exec('CREATE TABLE bench_hits (id INTEGER PRIMARY KEY, seen_at INTEGER)');
            self::$pdo->exec('INSERT INTO bench_hits (seen_at) VALUES (' . time() . ')');
        }

        return self::$pdo;
    }
}

final class BenchStatsController extends SocketController
{
    #[HttpRoute('GET', '/stats')]
    public function stats(Request $request): Response
    {
        return Response::json($this->getServerStats());
    }
}

final class BenchPresenceController extends SocketController
{
    #[OnConnect]
    public function onConnect(string $clientId): void
    {
        // ponytail: RedisNamespaceManager filters room members without client data store presence
        $this->setClientData($clientId, 'bench', true);
    }
}

final class BenchTraceMiddleware implements WebsocketMiddleware
{
    public function handle(string $clientId, string $event, array $data, callable $next, Server $server): mixed
    {
        return $next($clientId, $data);
    }
}

/**
 * @return array<string, mixed>
 */
function benchRedisScaleConfig(string $nodeId): array
{
    return [
        'node_id' => $nodeId,
        'registry' => 'redis',
        'publisher' => 'redis',
        'redis' => [
            'database' => 15,
            'prefix' => 'sockeon:bench:',
            'channel' => 'sockeon:bench:broadcast',
        ],
    ];
}

$port = isset($argv[1]) ? (int) $argv[1] : 0;
$maxConnections = isset($argv[2]) ? (int) $argv[2] : 10_000;
$engine = $argv[3] ?? 'swoole';
$profile = $argv[4] ?? 'default';
$bindHost = $argv[5] ?? '127.0.0.1';

if ($port <= 0) {
    fwrite(STDERR, "Usage: bench_server.php <port> [max_connections] [engine] [profile] [bind_host]\n");
    fwrite(STDERR, "Profiles: default, handled, scaled, node-a, node-b, realistic\n");
    fwrite(STDERR, "Remote clients: bind_host=0.0.0.0 (default 127.0.0.1)\n");
    exit(1);
}

$logger = new Logger(
    minLogLevel: LogLevel::ERROR,
    logToConsole: false,
    logToFile: false,
);

$swoole = match ($profile) {
    'scaled' => ['worker_num' => 4],
    default => ['worker_num' => 1],
};

$configArray = [
    'engine' => $engine,
    'health_check_path' => '/health',
    'survivability' => ['max_connections' => $maxConnections],
    'swoole' => $swoole,
];

$configArray['scale'] = match ($profile) {
    'scaled' => benchRedisScaleConfig('bench-node-1'),
    'node-a' => benchRedisScaleConfig('bench-node-a'),
    'node-b' => benchRedisScaleConfig('bench-node-b'),
    default => null,
};

if ($configArray['scale'] === null) {
    unset($configArray['scale']);
}

$config = new ServerConfig($configArray);
$config->setHost($bindHost);
$config->setPort($port);
$config->setLogger($logger);

$server = new Server($config);
$server->registerController(new BenchEchoController());
$server->registerController(new BenchStatsController());

if ($profile === 'handled' || $profile === 'realistic') {
    $server->addWebSocketMiddleware(BenchTraceMiddleware::class);
    $server->registerController(new BenchHandledController());
}

if ($profile === 'realistic') {
    $server->registerController(new BenchDbController());
}

if (in_array($profile, ['scaled', 'node-a', 'node-b'], true)) {
    $server->registerController(new BenchPresenceController());
}

$server->run();
