<?php

namespace MxBackup\Core\Masking;

use InvalidArgumentException;

final class Rule
{
    private $table;
    private $column;
    private $action;
    private $value;
    private $priority;
    private $jsonPath;

    public function __construct($table, $column, $action, $value = null, $priority = 0, $jsonPath = null)
    {
        if (!in_array($action, ['mask', 'hide', 'truncate', 'hash', 'replace'], true)) {
            throw new InvalidArgumentException('Неизвестное действие masking: ' . $action);
        }
        $this->table = (string)$table;
        $this->column = (string)$column;
        $this->action = $action;
        $this->value = $value;
        $this->priority = (int)$priority;
        $this->jsonPath = $jsonPath === null ? null : (string)$jsonPath;
    }

    public function matches($table, $column = null)
    {
        if (!fnmatch($this->table, $table)) {
            return false;
        }
        return $column === null || $this->column === '*' || fnmatch($this->column, $column);
    }

    public function getColumn() { return $this->column; }
    public function getAction() { return $this->action; }
    public function getValue() { return $this->value; }
    public function getPriority() { return $this->priority; }
    public function getJsonPath() { return $this->jsonPath; }
}
