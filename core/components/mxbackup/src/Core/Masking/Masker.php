<?php

namespace MxBackup\Core\Masking;

final class Masker
{
    private $rules;
    private $jsonMasker;
    private $requiredColumns;

    public function __construct(array $rules, JsonPathMasker $jsonMasker = null, array $requiredColumns = [])
    {
        usort($rules, static function (Rule $a, Rule $b) {
            return $a->getPriority() <=> $b->getPriority();
        });
        $this->rules = $rules;
        $this->jsonMasker = $jsonMasker ?: new JsonPathMasker();
        $this->requiredColumns = $requiredColumns;
    }

    public function shouldTruncate($table)
    {
        foreach ($this->rules as $rule) {
            if ($rule->getAction() === 'truncate' && $rule->matches($table)) {
                return true;
            }
        }
        return false;
    }

    public function maskRow($table, array $row, array $columnMeta = [])
    {
        $rowSeed = $this->rowSeed($row);
        foreach ($row as $column => $value) {
            foreach ($this->rules as $rule) {
                if (!$rule->matches($table, $column) || $rule->getAction() === 'truncate') {
                    continue;
                }
                $value = $this->transform($rule, $column, $value, $table . '|' . $rowSeed . '|' . $column, $columnMeta);
            }
            $row[$column] = $value;
        }
        return $row;
    }

    public function validateTable($table, array $columnMeta)
    {
        foreach ($this->requiredColumns as $pattern => $specification) {
            if (!fnmatch($pattern, $table)) {
                continue;
            }
            $columns = $specification;
            if (isset($specification['required'])) {
                $excluded = isset($specification['exclude']) ? $specification['exclude'] : [];
                foreach ($excluded as $excludedPattern) {
                    if (fnmatch($excludedPattern, $table)) {
                        continue 2;
                    }
                }
                $anchors = isset($specification['anchors_any']) ? $specification['anchors_any'] : [];
                if ($anchors && !array_intersect($anchors, array_keys($columnMeta))) {
                    continue;
                }
                $columns = $specification['required'];
            }
            $missing = array_diff($columns, array_keys($columnMeta));
            if ($missing) {
                throw new \RuntimeException(
                    'Dev backup остановлен: для таблицы ' . $table
                    . ' отсутствуют обязательные поля masking: ' . implode(', ', $missing)
                );
            }
        }
    }

    public function planTable($table, array $columnMeta)
    {
        $this->validateTable($table, $columnMeta);
        if ($this->shouldTruncate($table)) {
            return ['truncated' => true, 'columns' => []];
        }
        $columns = [];
        foreach (array_keys($columnMeta) as $column) {
            foreach ($this->rules as $rule) {
                if ($rule->getAction() !== 'truncate' && $rule->matches($table, $column)) {
                    $columns[$column] = $rule->getAction();
                }
            }
        }
        return ['truncated' => false, 'columns' => $columns];
    }

    private function transform(Rule $rule, $column, $value, $seed, array $columnMeta)
    {
        $action = $rule->getAction();
        if ($rule->getJsonPath() !== null) {
            return $this->jsonMasker->applyPath($value, $rule->getJsonPath(), $action, $rule->getValue(), $seed);
        }
        if ($action === 'replace') return $rule->getValue();
        if ($action === 'hide') return $this->emptyValue($column, $columnMeta);
        if ($action === 'hash') return hash('sha256', $seed . '|' . (string)$value);

        if (in_array($column, ['properties', 'extended'], true)) {
            return $this->jsonMasker->mask($value, $seed);
        }
        $id = hexdec(substr(hash('sha256', $seed), 0, 7));
        if ($column === 'email') return 'user' . $id . '@example.test';
        if (in_array($column, ['phone', 'mobilephone', 'fax'], true)) {
            return '+7000' . str_pad((string)($id % 10000000), 7, '0', STR_PAD_LEFT);
        }
        if (in_array($column, ['fullname', 'receiver', 'username'], true)) return 'User ' . $id;
        if ($column === 'ip') return '192.0.2.' . (($id % 253) + 1);
        if (in_array($column, ['zip', 'index'], true)) return str_pad((string)($id % 1000000), 6, '0', STR_PAD_LEFT);
        return 'Test ' . $id;
    }

    private function emptyValue($column, array $columnMeta)
    {
        if (isset($columnMeta[$column]['Null']) && strtoupper((string)$columnMeta[$column]['Null']) === 'YES') {
            return null;
        }
        return '';
    }

    private function rowSeed(array $row)
    {
        foreach (['id', 'internalKey', 'order_id', 'user_id'] as $key) {
            if (array_key_exists($key, $row)) return (string)$row[$key];
        }
        return hash('sha256', json_encode($row));
    }
}
