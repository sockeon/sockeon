<?php

namespace Sockeon\Sockeon\Engine;

use RuntimeException;
use Sockeon\Sockeon\Config\SwooleEngineConfig;

class SwooleEngineTables
{
    public \Swoole\Table $clients;

    public \Swoole\Table $fdMap;

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
     * @return \Swoole\Table
     */
    private static function createClientsTable(int $size): \Swoole\Table
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
     * @return \Swoole\Table
     */
    private static function createFdMapTable(int $size): \Swoole\Table
    {
        /** @var \Swoole\Table $table */
        $table = new \Swoole\Table($size);
        $table->column('client_id', \Swoole\Table::TYPE_STRING, 64);
        $table->create();

        return $table;
    }
}
