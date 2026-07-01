<?php

namespace Tests\Support;

use RuntimeException;

final class BackgroundServer
{
    /**
     * @param array{max_connections?: int, engine?: string} $options
     * @return resource
     */
    public static function start(int $port, array $options = [])
    {
        $maxConnections = $options['max_connections'] ?? 10_000;
        $engine = $options['engine'] ?? 'stream_select';
        $fixture = dirname(__DIR__) . '/fixtures/run_server.php';
        $command = sprintf(
            '%s %s %d %d %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($fixture),
            $port,
            $maxConnections,
            escapeshellarg($engine)
        );

        $descriptors = [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start background Sockeon server');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);

        $stdout = '';
        $deadline = microtime(true) + 10.0;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                throw new RuntimeException('Background Sockeon server exited before becoming ready');
            }

            $chunk = fread($pipes[1], 8192);
            if (is_string($chunk) && $chunk !== '') {
                $stdout .= $chunk;
            }

            if (self::isPortListening('127.0.0.1', $port)) {
                fclose($pipes[1]);
                fclose($pipes[2]);

                return $process;
            }

            usleep(20_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        self::stop($process);
        throw new RuntimeException("Sockeon server did not become ready on port {$port}");
    }

    /**
     * @param resource $process
     */
    public static function stop($process): void
    {
        if (!is_resource($process)) {
            return;
        }

        proc_terminate($process);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            usleep(50_000);
        }

        proc_close($process);
    }

    private static function isPortListening(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 0.2);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
