<?php

namespace MxBackup\Core\Database;

use MxBackup\Core\Contract\DatabaseAdapterInterface;
use MxBackup\Core\Masking\Masker;
use RuntimeException;

final class DatabaseDumper
{
    private $database;
    private $masker;

    public function __construct(DatabaseAdapterInterface $database, Masker $masker = null)
    {
        $this->database = $database;
        $this->masker = $masker;
    }

    public function dump($path, array $tables, $batchSize = 500)
    {
        $handle = fopen($path, 'wb');
        if (!$handle) {
            throw new RuntimeException('Не удалось открыть SQL dump: ' . $path);
        }
        $report = ['tables' => [], 'rows' => 0, 'warnings' => []];
        fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $snapshot = false;
        try {
            $this->database->beginConsistentSnapshot();
            $snapshot = true;
            foreach ($tables as $table) {
                $meta = $this->database->describeTable($table);
                if ($this->masker) {
                    $this->masker->validateTable($table, $meta);
                }
                $create = $this->database->getCreateTableSql($table);
                fwrite($handle, '-- Table ' . $table . "\nDROP TABLE IF EXISTS `" . str_replace('`', '``', $table) . "`;\n" . $create . ";\n");

                $transactional = $this->database->isTransactional($table);
                if (!$transactional) {
                    $report['warnings'][] = 'Таблица ' . $table . ' не поддерживает consistent snapshot.';
                }
                $rows = 0;
                $truncated = $this->masker && $this->masker->shouldTruncate($table);
                if (!$truncated) {
                    foreach ($this->database->iterateRows($table, $batchSize) as $row) {
                        if ($this->masker) {
                            $row = $this->masker->maskRow($table, $row, $meta);
                        }
                        $columns = array_map([$this, 'identifier'], array_keys($row));
                        $values = [];
                        foreach ($row as $value) {
                            $values[] = $this->database->quote($value);
                        }
                        fwrite($handle, 'INSERT INTO ' . $this->identifier($table) . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n");
                        $rows++;
                    }
                }
                fwrite($handle, "\n");
                $report['tables'][$table] = ['rows' => $rows, 'truncated' => $truncated, 'transactional' => $transactional];
                $report['rows'] += $rows;
            }
            $this->database->finishConsistentSnapshot(true);
            $snapshot = false;
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (\Throwable $e) {
            if ($snapshot) {
                $this->database->finishConsistentSnapshot(false);
            }
            fclose($handle);
            throw $e;
        }
        fclose($handle);
        $report['size'] = filesize($path) ?: 0;
        $report['checksum'] = hash_file('sha256', $path);
        return $report;
    }

    private function identifier($name)
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
