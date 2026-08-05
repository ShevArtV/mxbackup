<?php
$definitions = [
    'mxbackup.storage_path' => ['', 'textfield', 'mxbackup_general'],
    'mxbackup.config_path' => ['', 'textfield', 'mxbackup_general'],
    'mxbackup.default_profile' => ['prod', 'textfield', 'mxbackup_general'],
    'mxbackup.archive_format' => ['tar.gz', 'textfield', 'mxbackup_general'],
    'mxbackup.mail_enabled' => ['0', 'combo-boolean', 'mxbackup_mail'],
    'mxbackup.mail_to' => ['', 'textfield', 'mxbackup_mail'],
    'mxbackup.mail_max_attachment_mb' => ['10', 'numberfield', 'mxbackup_mail'],
    'mxbackup.retention_days' => ['30', 'numberfield', 'mxbackup_retention'],
    'mxbackup.retention_count' => ['10', 'numberfield', 'mxbackup_retention'],
    'mxbackup.lock_ttl_minutes' => ['720', 'numberfield', 'mxbackup_retention'],
];
$settings = [];
foreach ($definitions as $key => $definition) {
    $setting = $this->modx->newObject('modSystemSetting');
    $setting->fromArray(['key'=>$key,'value'=>$definition[0],'xtype'=>$definition[1],'namespace'=>$namespace,'area'=>$definition[2],'editedon'=>null], '', true, true);
    $settings[] = $setting;
}
return $settings;
