<?php

class mxBackupConfigUpdateProcessor extends modProcessor
{
    private $fields = [
        'storage_path' => 'mxbackup.storage_path',
        'config_dir' => 'mxbackup.config_dir',
        'config_path' => 'mxbackup.config_path',
        'default_profile' => 'mxbackup.default_profile',
        'archive_format' => 'mxbackup.archive_format',
        'mail_enabled' => 'mxbackup.mail_enabled',
        'mail_to' => 'mxbackup.mail_to',
        'mail_max_attachment_mb' => 'mxbackup.mail_max_attachment_mb',
        'retention_days' => 'mxbackup.retention_days',
        'retention_count' => 'mxbackup.retention_count',
        'lock_ttl_minutes' => 'mxbackup.lock_ttl_minutes',
    ];

    public function checkPermissions()
    {
        return $this->modx->hasPermission('mxbackup_manage');
    }

    public function process()
    {
        $properties = $this->getProperties();
        $xtypes = [
            'mxbackup.mail_enabled' => 'combo-boolean',
            'mxbackup.mail_max_attachment_mb' => 'numberfield',
            'mxbackup.retention_days' => 'numberfield',
            'mxbackup.retention_count' => 'numberfield',
            'mxbackup.lock_ttl_minutes' => 'numberfield',
        ];

        foreach ($this->fields as $field => $key) {
            if (array_key_exists($field, $properties)) {
                $value = $properties[$field];
            } else {
                // PHP converts dots in legacy POST field names to underscores.
                $legacyField = str_replace('.', '_', $key);
                if (!array_key_exists($legacyField, $properties)) {
                    continue;
                }
                $value = $properties[$legacyField];
            }

            $setting = $this->modx->getObject('modSystemSetting', ['key' => $key]);
            if (!$setting) {
                $setting = $this->modx->newObject('modSystemSetting');
                $setting->set('key', $key);
                $setting->set('namespace', 'mxbackup');
                $setting->set('xtype', isset($xtypes[$key]) ? $xtypes[$key] : 'textfield');
            }
            $setting->set('value', (string) $value);
            $setting->set('editedon', time());
            $setting->save();
        }

        $this->modx->getCacheManager()->refresh(['system_settings' => []]);

        return $this->success($this->modx->lexicon('mxbackup_settings_saved'));
    }
}

return 'mxBackupConfigUpdateProcessor';
