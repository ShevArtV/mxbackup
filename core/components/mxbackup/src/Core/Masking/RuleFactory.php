<?php

namespace MxBackup\Core\Masking;

use InvalidArgumentException;

final class RuleFactory
{
    public function createMany(array $rows)
    {
        $rules = [];
        foreach ($rows as $row) {
            if ($row instanceof Rule) {
                $rules[] = $row;
                continue;
            }
            if (!is_array($row) || isset($row['active']) && !$row['active']) {
                continue;
            }
            $targetType = isset($row['target_type']) ? (string)$row['target_type'] : 'column';
            $target = isset($row['target']) ? trim((string)$row['target']) : '';
            if ($target === '') {
                throw new InvalidArgumentException('У masking-правила не задан target.');
            }
            if ($targetType === 'table') {
                $table = $target;
                $column = '*';
                $jsonPath = null;
                if ((isset($row['action']) ? (string)$row['action'] : '') === 'hide') {
                    $row['action'] = 'truncate';
                }
            } elseif ($targetType === 'column') {
                $parts = explode('.', $target, 2);
                if (count($parts) !== 2) {
                    throw new InvalidArgumentException('Column target должен иметь вид table.column: ' . $target);
                }
                [$table, $column] = $parts;
                $jsonPath = null;
            } elseif ($targetType === 'json_path') {
                $parts = explode('.', $target, 3);
                if (count($parts) !== 3) {
                    throw new InvalidArgumentException('Json path target должен иметь вид table.column.path: ' . $target);
                }
                [$table, $column, $jsonPath] = $parts;
            } else {
                continue;
            }
            $rules[] = new Rule(
                $table,
                $column,
                isset($row['action']) ? (string)$row['action'] : 'mask',
                isset($row['value']) ? $row['value'] : null,
                isset($row['priority']) ? (int)$row['priority'] : 0,
                $jsonPath
            );
        }
        return $rules;
    }
}
