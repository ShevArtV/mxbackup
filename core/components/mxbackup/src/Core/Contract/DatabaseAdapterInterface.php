<?php

namespace MxBackup\Core\Contract;

interface DatabaseAdapterInterface
{
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
