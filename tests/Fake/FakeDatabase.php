<?php

namespace MxBackup\Tests\Fake;

use MxBackup\Core\Contract\DatabaseAdapterInterface;

final class FakeDatabase implements DatabaseAdapterInterface
{
    private $tables;

    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    public function listTables() { return array_keys($this->tables); }
    public function getCreateTableSql($table) { return 'CREATE TABLE `' . $table . '` (`id` int NOT NULL) ENGINE=InnoDB'; }
    public function describeTable($table)
    {
        $columns = [];
        foreach ($this->tables[$table] as $row) {
            foreach ($row as $column => $value) {
                $columns[$column] = ['Field' => $column, 'Null' => 'YES'];
            }
        }
        return $columns;
    }
    public function iterateRows($table, $batchSize)
    {
        foreach ($this->tables[$table] as $row) yield $row;
    }
    public function quote($value)
    {
        if ($value === null) return 'NULL';
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }
    public function beginConsistentSnapshot() {}
    public function finishConsistentSnapshot($commit = true) {}
    public function isTransactional($table) { return true; }
}
