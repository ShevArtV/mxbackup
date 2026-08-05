<?php
class mxBackupConfigUpdateProcessor extends modProcessor
{
    private $keys=['mxbackup.storage_path','mxbackup.config_path','mxbackup.default_profile','mxbackup.archive_format','mxbackup.mail_enabled','mxbackup.mail_to','mxbackup.mail_max_attachment_mb','mxbackup.retention_days','mxbackup.retention_count','mxbackup.lock_ttl_minutes'];
    public function checkPermissions(){return $this->modx->hasPermission('mxbackup_manage');}
    public function process(){
        $xtype=['mxbackup.mail_enabled'=>'combo-boolean','mxbackup.mail_max_attachment_mb'=>'numberfield','mxbackup.retention_days'=>'numberfield','mxbackup.retention_count'=>'numberfield','mxbackup.lock_ttl_minutes'=>'numberfield'];
        foreach($this->keys as $key){$setting=$this->modx->getObject('modSystemSetting',['key'=>$key]);if(!$setting){$setting=$this->modx->newObject('modSystemSetting');$setting->set('key',$key);$setting->set('namespace','mxbackup');$setting->set('xtype',isset($xtype[$key])?$xtype[$key]:'textfield');}$setting->set('value',(string)$this->getProperty($key,''));$setting->set('editedon',time());$setting->save();}
        $this->modx->getCacheManager()->refresh(['system_settings'=>[]]);return $this->success($this->modx->lexicon('mxbackup_settings_saved'));
    }
}
return 'mxBackupConfigUpdateProcessor';
