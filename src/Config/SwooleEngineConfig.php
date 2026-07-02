<?php

namespace Sockeon\Sockeon\Config;

class SwooleEngineConfig
{
    protected ?int $workerNum = null;

    protected int $taskWorkerNum = 0;

    protected int $maxConnection = 100000;

    protected int $clientTableSize = 131072;

    protected bool $coroutineDispatch = true;

    protected ?string $memoryLimit = null;

    protected int $socketBufferSize;

    protected int $bufferOutputSize;

    protected int $backlog;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        if (isset($config['worker_num']) && is_numeric($config['worker_num'])) {
            $this->workerNum = (int) $config['worker_num'];
        }

        if (isset($config['task_worker_num']) && is_numeric($config['task_worker_num'])) {
            $this->taskWorkerNum = (int) $config['task_worker_num'];
        }

        if (isset($config['max_connection']) && is_numeric($config['max_connection'])) {
            $this->maxConnection = (int) $config['max_connection'];
        }

        $this->socketBufferSize = isset($config['socket_buffer_size']) && is_numeric($config['socket_buffer_size'])
            ? max(8192, (int) $config['socket_buffer_size'])
            : $this->defaultBufferSize($this->maxConnection);

        $this->bufferOutputSize = isset($config['buffer_output_size']) && is_numeric($config['buffer_output_size'])
            ? max(8192, (int) $config['buffer_output_size'])
            : $this->socketBufferSize;

        $this->backlog = isset($config['backlog']) && is_numeric($config['backlog'])
            ? max(128, (int) $config['backlog'])
            : min(8192, max(1024, (int) ($this->maxConnection / 2)));

        if (isset($config['client_table_size']) && is_numeric($config['client_table_size'])) {
            $this->clientTableSize = (int) $config['client_table_size'];
        } else {
            // ponytail: +2048 headroom for concurrent handshakes; 131072 default pre-allocates ~100MB+ unused
            $this->clientTableSize = (int) min(131072, max(2048, $this->maxConnection + 2048));
        }

        if (isset($config['coroutine_dispatch'])) {
            $this->coroutineDispatch = (bool) $config['coroutine_dispatch'];
        }

        if (isset($config['memory_limit']) && is_string($config['memory_limit']) && $config['memory_limit'] !== '') {
            $this->memoryLimit = $config['memory_limit'];
        }
    }

    private function defaultBufferSize(int $maxConnection): int
    {
        // ponytail: 128KB × 2 × 10k ≈ 2.5GB kernel buffer budget; 32KB saves ~1.9GB
        if ($maxConnection >= 10_000) {
            return 32768;
        }

        if ($maxConnection >= 5_000) {
            return 65536;
        }

        return 131072;
    }

    public function getWorkerNum(): int
    {
        if ($this->workerNum !== null) {
            return max(1, $this->workerNum);
        }

        if (function_exists('swoole_cpu_num')) {
            return max(1, swoole_cpu_num());
        }

        return 4;
    }

    public function getTaskWorkerNum(): int
    {
        return max(0, $this->taskWorkerNum);
    }

    public function getMaxConnection(): int
    {
        return $this->maxConnection;
    }

    public function getClientTableSize(): int
    {
        return $this->clientTableSize;
    }

    public function useCoroutineDispatch(): bool
    {
        return $this->coroutineDispatch;
    }

    public function getMemoryLimit(): string
    {
        if ($this->memoryLimit !== null) {
            return $this->memoryLimit;
        }

        // ponytail: Laravel + 10k live sockets needs headroom; 512M/1G OOM under load
        if ($this->maxConnection >= 10_000) {
            return '2G';
        }

        if ($this->maxConnection >= 5_000) {
            return '1G';
        }

        $mb = max(256, 128 + (int) ceil($this->maxConnection / 64));

        return $mb . 'M';
    }

    public function getSocketBufferSize(): int
    {
        return $this->socketBufferSize;
    }

    public function getBufferOutputSize(): int
    {
        return $this->bufferOutputSize;
    }

    public function getBacklog(): int
    {
        return $this->backlog;
    }
}
