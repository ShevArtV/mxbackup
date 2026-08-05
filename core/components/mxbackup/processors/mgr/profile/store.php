<?php

final class mxBackupProfileStoreHelper
{
    public static function store(modX $modx)
    {
        $corePath = $modx->getOption('mxbackup.core_path', null, MODX_CORE_PATH . 'components/mxbackup/');
        require_once $corePath . 'autoload.php';
        return (new \MxBackup\Platform\Modx2\ProfileRepository($modx))->getStore();
    }

    public static function row(array $profile)
    {
        return [
            'id' => (string) $profile['name'],
            'name' => (string) $profile['name'],
            'description' => isset($profile['description']) ? (string) $profile['description'] : '',
            'mode' => isset($profile['mode']) ? (string) $profile['mode'] : 'custom',
            'active' => !empty($profile['active']),
            'format' => isset($profile['format']) ? (string) $profile['format'] : 'tar.gz',
            'encryption_enabled' => !empty($profile['encryption']['enabled']),
            'encryption_password_set' => isset($profile['encryption']['password'])
                && (string) $profile['encryption']['password'] !== '',
            'file_include' => implode("\n", isset($profile['files']['include']) ? $profile['files']['include'] : ['*']),
            'file_exclude' => implode("\n", isset($profile['files']['exclude']) ? $profile['files']['exclude'] : []),
            'standard_masking' => !empty($profile['masking']['standard']),
            'createdon' => isset($profile['createdon']) ? (int) $profile['createdon'] : 0,
            'editedon' => isset($profile['editedon']) ? (int) $profile['editedon'] : 0,
        ];
    }

    public static function boolean($value)
    {
        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }
}
