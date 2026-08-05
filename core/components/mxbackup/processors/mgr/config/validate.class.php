<?php
class mxBackupConfigValidateProcessor extends modProcessor
{
    public function checkPermissions(){return $this->modx->hasPermission('mxbackup_view');}
    public function process(){
        $corePath=$this->modx->getOption('mxbackup.core_path',null,MODX_CORE_PATH.'components/mxbackup/');require_once $corePath.'autoload.php';
        try{$config=\MxBackup\Bootstrap::config($this->modx,['profile'=>(string)$this->getProperty('profile','prod')]);$errors=(new \MxBackup\Core\Config\ConfigValidator())->validate($config);return $errors?$this->failure(implode('; ',$errors)):$this->success($this->modx->lexicon('mxbackup_config_valid'));}catch(Throwable $e){return $this->failure($e->getMessage());}
    }
}
return 'mxBackupConfigValidateProcessor';
