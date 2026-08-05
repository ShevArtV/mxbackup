<?php

class mxBackupConfigGetProcessor extends modProcessor
{
    private $fields = [
        'storage_path' => 'mxbackup.storage_path',
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
        return $this->modx->hasPermission('mxbackup_view');
    }

    public function process()
    {
        $values = [];
        foreach ($this->fields as $field => $key) {
            $values[$field] = $this->modx->getOption($key, null, '');
        }

        return $this->success('', $values);
    }
}

return 'mxBackupConfigGetProcessor';
