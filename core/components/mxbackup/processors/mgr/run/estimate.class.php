<?php
class mxBackupRunEstimateProcessor extends modProcessor
{
    public function checkPermissions(){return $this->modx->hasPermission('mxbackup_run');}
    public function process(){
        set_time_limit(0);
        $corePath=$this->modx->getOption('mxbackup.core_path',null,MODX_CORE_PATH.'components/mxbackup/');require_once $corePath.'autoload.php';
        try{
            $config=\MxBackup\Bootstrap::config($this->modx,['profile'=>(string)$this->getProperty('profile','prod')]);
            $estimate=(new \MxBackup\Core\BackupEstimator(\MxBackup\Bootstrap::platform($this->modx)))->estimate($config);
            return $this->success('', $estimate);
        }catch(Throwable $e){return $this->failure($e->getMessage());}
    }
}
return 'mxBackupRunEstimateProcessor';
