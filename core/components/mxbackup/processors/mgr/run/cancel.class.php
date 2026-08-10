<?php
class mxBackupRunCancelProcessor extends modProcessor
{
    public function checkPermissions(){return $this->modx->hasPermission('mxbackup_run');}
    public function process(){
        $corePath=$this->modx->getOption('mxbackup.core_path',null,MODX_CORE_PATH.'components/mxbackup/');require_once $corePath.'autoload.php';
        $id=(int)$this->getProperty('id',0);
        $ok=\MxBackup\Bootstrap::platform($this->modx)->runs()->requestCancel($id);
        return $ok ? $this->success('Запрошена остановка резервного копирования.') : $this->failure('Активный backup не найден.');
    }
}
return 'mxBackupRunCancelProcessor';
