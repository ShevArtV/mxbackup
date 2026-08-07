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
        $current['encryption'] = isset($current['encryption']) && is_array($current['encryption'])
            ? $current['encryption']
            : ['enabled' => false, 'password' => ''];
        $encryptionEnabled = array_key_exists('encryption_enabled', $properties)
            ? $this->boolean($properties['encryption_enabled'])
            : !empty($current['encryption']['enabled']);
        if (array_key_exists('encryption_password', $properties)
            && (string) $properties['encryption_password'] !== '') {
            $current['encryption']['password'] = (string) $properties['encryption_password'];
        }
        $current['encryption']['enabled'] = $encryptionEnabled;
        if (!$encryptionEnabled) {
            $current['encryption']['password'] = '';
        } elseif ($format !== 'zip') {
            throw new InvalidArgumentException('Шифрование доступно только для ZIP.');
        } elseif (empty($current['encryption']['password'])) {
            throw new InvalidArgumentException('Для шифрования задайте пароль архива.');
        }
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
        $current['remote'] = $this->remoteSection($current, $properties);

        return $current;
    }

    /**
     * Секция удалённого хранилища из полей формы.
     *
     * ⚠️ Пустой секрет означает «не менять», а не «стереть»: форма его обратно
     * не показывает (как и пароль архива), и сохранение профиля ради правки
     * префикса иначе молча обнуляло бы доступ к бакету.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function remoteSection(array $current, array $properties)
    {
        $remote = isset($current['remote']) && is_array($current['remote'])
            ? $current['remote']
            : Defaults::remoteTemplate();
        $s3 = isset($remote['s3']) && is_array($remote['s3']) ? $remote['s3'] : [];

        if (array_key_exists('remote_driver', $properties)) {
            $driver = trim((string) $properties['remote_driver']);
            if (!in_array($driver, ['', 'none', 's3'], true)) {
                throw new InvalidArgumentException('Недопустимый драйвер удалённого хранилища.');
            }
            $remote['driver'] = $driver === 'none' ? '' : $driver;
        }

        if (array_key_exists('remote_keep_local', $properties)) {
            $keep = (int) $properties['remote_keep_local'];
            if ($keep < 1) {
                throw new InvalidArgumentException('Локально должна оставаться хотя бы одна копия.');
            }
            $remote['keep_local'] = $keep;
        }

        $retention = isset($remote['retention']) && is_array($remote['retention'])
            ? $remote['retention']
            : ['days' => 0, 'count' => 0];
        foreach (['days' => 'remote_retention_days', 'count' => 'remote_retention_count'] as $key => $field) {
            if (array_key_exists($field, $properties)) {
                $retention[$key] = max(0, (int) $properties[$field]);
            }
        }
        $remote['retention'] = $retention;

        foreach (['bucket', 'region', 'prefix', 'endpoint', 'storage_class', 'access_key'] as $field) {
            if (array_key_exists('remote_s3_' . $field, $properties)) {
                $s3[$field] = trim((string) $properties['remote_s3_' . $field]);
            }
        }
        foreach (['secret_key', 'session_token'] as $field) {
            if (array_key_exists('remote_s3_' . $field, $properties)
                && (string) $properties['remote_s3_' . $field] !== '') {
                $s3[$field] = (string) $properties['remote_s3_' . $field];
            }
        }

        // Ключ стёрли осознанно — значит доступ должен браться из окружения или
        // роли инстанса, и секрет обязан уйти следом: иначе останется половина
        // пары, при которой цепочка поиска ведёт себя непредсказуемо.
        if (array_key_exists('remote_s3_access_key', $properties) && $s3['access_key'] === '') {
            $s3['secret_key'] = '';
            $s3['session_token'] = '';
        }

        $remote['s3'] = $s3;

        return $remote;
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

    public function filterTables(array $tables, $query)
    {
        $query = mb_strtolower(trim((string)$query), 'UTF-8');
        if ($query === '') {
            return array_values($tables);
        }

        $matched = [];
        foreach ($tables as $table) {
            $table = (string)$table;
            if (mb_strpos(mb_strtolower($table, 'UTF-8'), $query, 0, 'UTF-8') !== false) {
                $matched[] = $table;
            }
        }
        return $matched;
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
