<?php

namespace MxBackup\Core\Contract;

interface DatabaseAdapterInterface
{
    /**
     * Поддержать соединение живым во время долгой работы без запросов.
     *
     * Упаковка архива и распаковка при восстановлении идут минутами, а сервер
     * базы закрывает простаивающее соединение по своему таймауту. Реализация
     * вправе ничего не делать, если платформе это не нужно.
     */
    public function keepAlive();

    public function listTables();
    public function getCreateTableSql($table);
    public function describeTable($table);
    public function iterateRows($table, $batchSize);
    public function quote($value);
    public function beginConsistentSnapshot();
    public function finishConsistentSnapshot($commit = true);
    public function isTransactional($table);
    public function executeRestoreStatement($sql);
}
