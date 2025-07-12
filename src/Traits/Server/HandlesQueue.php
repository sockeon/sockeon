<?php

namespace Sockeon\Sockeon\Traits\Server;

use Sockeon\Sockeon\Core\Config;

trait HandlesQueue
{
    protected function processQueue(string $queueFile): void
    {
        if (!file_exists($queueFile) || !is_readable($queueFile)) {
            return;
        }

        $fp = fopen($queueFile, 'r+');
        if ($fp === false) {
            $this->logger->error("[Queue] Failed to open queue file.");
            return;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return;
        }

        $lines = [];
        while (($line = fgets($fp)) !== false) {
            $lines[] = trim($line);
        }

        ftruncate($fp, 0);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        foreach ($lines as $line) {
            if (empty($line)) continue;

            $payload = json_decode($line, true);
            if (!is_array($payload) || !isset($payload['type'])) {
                continue;
            }

            $type = $payload['type'];
            if (!is_string($type)) {
                continue;
            }

            /** @var array<string, mixed> $typedPayload */
            $typedPayload = $payload;

            match ($type) {
                'emit' => $this->handleEmitQueue($typedPayload),
                'broadcast' => $this->handleBroadcastQueue($typedPayload),
                default => $this->logger->warning("[Queue] Unknown message type: {$type}")
            };
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function handleEmitQueue(array $payload): void
    {
        $clientIdRaw = $payload['clientId'] ?? 0;
        $clientId = is_int($clientIdRaw) ? $clientIdRaw : (is_numeric($clientIdRaw) ? (int)$clientIdRaw : 0);
        $eventRaw = $payload['event'] ?? '';
        $event = is_string($eventRaw) ? $eventRaw : '';
        
        /** @var array<string, mixed> $data */
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if ($clientId && $event) {
            $this->send($clientId, $event, $data);
        } else {
            $this->logger->warning("[Queue] Invalid emit payload");
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function handleBroadcastQueue(array $payload): void
    {
        $eventRaw = $payload['event'] ?? '';
        $event = is_string($eventRaw) ? $eventRaw : '';
        
        /** @var array<string, mixed> $data */
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        
        $namespaceRaw = $payload['namespace'] ?? null;
        $namespace = is_string($namespaceRaw) ? $namespaceRaw : null;
        $roomRaw = $payload['room'] ?? null;
        $room = is_string($roomRaw) ? $roomRaw : null;

        if ($event) {
            $this->broadcast($event, $data, $namespace, $room);
        } else {
            $this->logger->warning("[Queue] Invalid broadcast payload");
        }
    }
}
