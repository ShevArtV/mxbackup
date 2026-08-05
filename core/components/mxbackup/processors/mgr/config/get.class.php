<?php
class mxBackupConfigGetProcessor extends modProcessor
{
    private $keys=['mxbackup.storage_path','mxbackup.config_path','mxbackup.default_profile','mxbackup.archive_format','mxbackup.mail_enabled','mxbackup.mail_to','mxbackup.mail_max_attachment_mb','mxbackup.retention_days','mxbackup.retention_count','mxbackup.lock_ttl_minutes'];
    public function checkPermissions(){return $this->modx->hasPermission('mxbackup_view');}
    public function process(){$values=[];foreach($this->keys as $key)$values[$key]=$this->modx->getOption($key,null,'');return $this->success('',$values);}
}
return 'mxBackupConfigGetProcessor';
