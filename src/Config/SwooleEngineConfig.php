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

        // ponytail: PHP's 128M default cannot hold Laravel + swoole connection buffers at scale
        $mb = max(512, 128 + (int) ceil($this->maxConnection / 64));

        return $mb . 'M';
    }
}
