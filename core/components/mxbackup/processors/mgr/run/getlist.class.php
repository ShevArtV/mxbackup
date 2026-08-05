<?php
class mxBackupRunGetListProcessor extends modObjectGetListProcessor
{
    public $classKey='mxBackupRun'; public $languageTopics=['mxbackup:default']; public $defaultSortField='startedon'; public $defaultSortDirection='DESC'; public $objectType='mxbackup_run';
    public function checkPermissions(){return $this->modx->hasPermission('mxbackup_view');}
}
return 'mxBackupRunGetListProcessor';
