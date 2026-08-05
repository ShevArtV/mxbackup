<?php

namespace MxBackup\Platform\Modx2;

use MxBackup\Core\Contract\DatabaseAdapterInterface;
use RuntimeException;

final class DatabaseAdapter implements DatabaseAdapterInterface
{
    private $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    public function listTables()
    {
        $statement = $this->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function getCreateTableSql($table)
    {
        $row = $this->query('SHOW CREATE TABLE ' . $this->identifier($table))->fetch(\PDO::FETCH_NUM);
        if (!$row || !isset($row[1])) {
            throw new RuntimeException('Не удалось получить CREATE TABLE для ' . $table);
        }
        return (string)$row[1];
    }

    public function describeTable($table)
    {
        $rows = $this->query('SHOW COLUMNS FROM ' . $this->identifier($table))->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['Field']] = $row;
        }
        return $result;
    }

    public function iterateRows($table, $batchSize)
    {
        $batchSize = max(1, (int)$batchSize);
        $offset = 0;
        do {
            $statement = $this->query('SELECT * FROM ' . $this->identifier($table) . ' LIMIT ' . $batchSize . ' OFFSET ' . $offset);
            $count = 0;
            while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
                $count++;
                yield $row;
            }
            $statement->closeCursor();
            $offset += $count;
        } while ($count === $batchSize);
    }

    public function quote($value)
    {
        if ($value === null) return 'NULL';
        if (is_bool($value)) return $value ? '1' : '0';
        $quoted = $this->modx->quote((string)$value);
        if ($quoted === false) {
            throw new RuntimeException('PDO не смог экранировать значение SQL.');
        }
        return $quoted;
    }

    public function beginConsistentSnapshot()
    {
        $this->modx->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        if ($this->modx->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT') === false) {
            throw new RuntimeException('Не удалось начать consistent snapshot.');
        }
    }

    public function finishConsistentSnapshot($commit = true)
    {
        $this->modx->exec($commit ? 'COMMIT' : 'ROLLBACK');
    }

    public function isTransactional($table)
    {
        $statement = $this->modx->prepare('SHOW TABLE STATUS LIKE ?');
        if (!$statement || !$statement->execute([$table])) {
            return false;
        }
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row && in_array(strtolower((string)$row['Engine']), ['innodb', 'ndbcluster'], true);
    }

    public function executeRestoreStatement($sql)
    {
        $result = $this->modx->exec((string)$sql);
        if ($result === false) {
            $error = $this->modx->errorInfo();
            $message = is_array($error) && !empty($error[2]) ? (string)$error[2] : 'неизвестная ошибка PDO';
            throw new RuntimeException('Ошибка SQL при восстановлении: ' . $message);
        }
        return $result;
    }

    private function query($sql)
    {
        $statement = $this->modx->query($sql);
        if (!$statement) {
            throw new RuntimeException('Ошибка SQL при создании dump.');
        }
        return $statement;
    }

    private function identifier($name)
    {
        if (!preg_match('/^[a-zA-Z0-9_$]+$/', (string)$name)) {
            throw new RuntimeException('Недопустимое имя таблицы: ' . $name);
        }
        return '`' . $name . '`';
    }
}
