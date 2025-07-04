<?php

/**
 * Abstract Event class
 * 
 * Base class for all WebSocket events in Sockeon
 * Provides standard methods for event identification and properties
 * 
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Core;

use Sockeon\Sockeon\Contracts\WebSocket\EventableContract;
use Throwable;

final class Event
{
    private function __construct()
    {
        //
    }

    /**
     * Statically broadcast an event to multiple clients
     *
     * @param EventableContract $event The event instance to broadcast
     * @return void
     */
    public static function broadcast(EventableContract $event): void
    {
        try {
            $eventName = $event->broadcastAs();
            $data = $event->broadcastWith();
            $rooms = $event->broadcastOn() ?? [];
            $namespace = $event->broadcastNamespace() ?? '/';

            foreach ($rooms as $room) {
                self::writeToQueue([
                    'type' => 'broadcast',
                    'event' => $eventName,
                    'data' => $data,
                    'namespace' => $namespace,
                    'room' => $room,
                ]);
            }
        } catch (Throwable $e) {
            error_log('Error broadcasting event: ' . $e->getMessage());
        }
    }

    /**
     * @param array<mixed> $payload
     * @return void
     */
    private static function writeToQueue(array $payload): void
    {
        $queueFile = Config::getQueueFile();

        file_put_contents(
            $queueFile,
            json_encode($payload) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
