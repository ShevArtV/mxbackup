<?php
class mxBackupRunCreateProcessor extends modProcessor
{
    public function checkPermissions(){return $this->modx->hasPermission('mxbackup_run');}
    public function process(){
        set_time_limit(0);
        $corePath=$this->modx->getOption('mxbackup.core_path',null,MODX_CORE_PATH.'components/mxbackup/');require_once $corePath.'autoload.php';
        try{
            $config=\MxBackup\Bootstrap::config($this->modx,['profile'=>(string)$this->getProperty('profile','prod')]);
            $result=\MxBackup\Bootstrap::runner($this->modx)->run($config,(bool)$this->getProperty('dry_run',false));
            $report=$result->getReport();
            $message=$result->getArchivePath()?('Архив: '.$result->getArchivePath()):'Dry-run завершён.';
            return $this->success($message,['archive'=>$result->getArchivePath(),'report'=>$report]);
        }catch(Throwable $e){return $this->failure($e->getMessage());}
    }
}
return 'mxBackupRunCreateProcessor';
