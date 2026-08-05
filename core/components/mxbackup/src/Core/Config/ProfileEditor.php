<?php

namespace MxBackup\Core\Config;

use InvalidArgumentException;
use MxBackup\Core\Database\TableSelector;

final class ProfileEditor
{
    public function update(array $current, array $properties)
    {
        $mode = isset($properties['mode']) ? (string)$properties['mode'] : 'custom';
        if (!in_array($mode, ['prod', 'dev', 'custom'], true)) {
            throw new InvalidArgumentException('Недопустимый режим профиля.');
        }
        $format = isset($properties['format']) ? (string)$properties['format'] : 'tar.gz';
        if (!in_array($format, ['zip', 'tar.gz'], true)) {
            throw new InvalidArgumentException('Недопустимый формат архива.');
        }

        $current['format'] = $format;
        $current['files'] = isset($current['files']) && is_array($current['files']) ? $current['files'] : [];
        $current['files']['include'] = $this->listValue(isset($properties['file_include']) ? $properties['file_include'] : ['*']);
        if (!$current['files']['include']) {
            $current['files']['include'] = ['*'];
        }
        $current['files']['exclude'] = $this->listValue(isset($properties['file_exclude']) ? $properties['file_exclude'] : []);
        $current['database'] = isset($current['database']) && is_array($current['database'])
            ? $current['database'] : ['include_tables' => ['*'], 'exclude_tables' => []];
        $current['masking'] = isset($current['masking']) && is_array($current['masking'])
            ? $current['masking'] : ['rules' => []];
        $current['masking']['standard'] = $mode === 'dev'
            ? true
            : $this->boolean(isset($properties['standard_masking']) ? $properties['standard_masking'] : false);
        $current['masking']['rules'] = isset($current['masking']['rules']) && is_array($current['masking']['rules'])
            ? $current['masking']['rules'] : [];

        return $current;
    }

    public function applyTableSelection(array $config, array $available, array $selected, $mode)
    {
        $available = array_values(array_unique(array_map('strval', $available)));
        sort($available, SORT_STRING);
        $selected = array_values(array_unique(array_map('strval', $selected)));
        sort($selected, SORT_STRING);
        if (!in_array($mode, ['all_except', 'selected'], true)) {
            throw new InvalidArgumentException('Недопустимый режим выбора таблиц.');
        }
        $unknown = array_diff($selected, $available);
        if ($unknown) {
            throw new InvalidArgumentException('Неизвестные таблицы: ' . implode(', ', $unknown));
        }

        $config['database'] = isset($config['database']) && is_array($config['database']) ? $config['database'] : [];
        if ($mode === 'all_except') {
            $config['database']['include_tables'] = ['*'];
            $config['database']['exclude_tables'] = array_values(array_diff($available, $selected));
        } else {
            $config['database']['include_tables'] = $selected;
            $config['database']['exclude_tables'] = [];
        }
        return $config;
    }

    public function selection(array $config, array $available)
    {
        $database = isset($config['database']) && is_array($config['database']) ? $config['database'] : [];
        $include = isset($database['include_tables']) && is_array($database['include_tables'])
            ? $database['include_tables'] : ['*'];
        $exclude = isset($database['exclude_tables']) && is_array($database['exclude_tables'])
            ? $database['exclude_tables'] : [];
        return (new TableSelector())->select($available, $include, $exclude);
    }

    public function selectionMode(array $config)
    {
        $include = isset($config['database']['include_tables']) && is_array($config['database']['include_tables'])
            ? $config['database']['include_tables'] : ['*'];
        return $include === ['*'] ? 'all_except' : 'selected';
    }

    public function listValue($value)
    {
        if (!is_array($value)) {
            $value = preg_split('/[\r\n,]+/', (string)$value);
        }
        $result = [];
        foreach ($value as $item) {
            $item = trim((string)$item);
            if ($item !== '' && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }
        return $result;
    }

    private function boolean($value)
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }
}
