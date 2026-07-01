<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Sockeon\Sockeon\Config\ScaleConfig;
use Sockeon\Sockeon\Core\RedisFactory;

$channel = $argv[1] ?? '';
$outFile = $argv[2] ?? '';

if ($channel === '' || $outFile === '') {
    fwrite(STDERR, "Usage: redis_capture.php <channel> <out_file>\n");
    exit(1);
}

$config = new ScaleConfig([
    'redis' => [
        'channel' => $channel,
        'database' => isset($argv[3]) ? (int) $argv[3] : 15,
    ],
]);

$redis = RedisFactory::connect($config);
$redis->subscribe([$channel], function ($redis, string $chan, string $message) use ($outFile): void {
    file_put_contents($outFile, $message);
    exit(0);
});
