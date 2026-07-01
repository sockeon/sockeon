<?php

namespace Sockeon\Sockeon\Engine;

use RuntimeException;
use Sockeon\Sockeon\Config\SwooleEngineConfig;

class SwooleEngineTables
{
    /**
     * @var object|null Swoole\Table
     */
    public ?object $clients = null;

    /**
     * @var object|null Swoole\Table
     */
    public ?object $fdMap = null;

    public static function create(SwooleEngineConfig $config): self
    {
        if (!class_exists(\Swoole\Table::class)) {
            throw new RuntimeException(
                'The Swoole extension is required for the swoole engine. Install ext-swoole or ext-openswoole.'
            );
        }

        $tables = new self();
        $size = $config->getClientTableSize();
        $tables->clients = self::createClientsTable($size);
        $tables->fdMap = self::createFdMapTable($size);

        return $tables;
    }

    /**
     * @return object Swoole\Table
     */
    private static function createClientsTable(int $size): object
    {
        /** @var \Swoole\Table $table */
        $table = new \Swoole\Table($size);
        $table->column('fd', \Swoole\Table::TYPE_INT);
        $table->column('type', \Swoole\Table::TYPE_STRING, 8);
        $table->column('worker_id', \Swoole\Table::TYPE_INT);
        $table->create();

        return $table;
    }

    /**
     * @return object Swoole\Table
     */
    private static function createFdMapTable(int $size): object
    {
        /** @var \Swoole\Table $table */
        $table = new \Swoole\Table($size);
        $table->column('client_id', \Swoole\Table::TYPE_STRING, 64);
        $table->create();

        return $table;
    }
}
