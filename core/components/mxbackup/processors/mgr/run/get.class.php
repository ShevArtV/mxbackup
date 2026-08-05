<?php
class mxBackupRunGetProcessor extends modObjectGetProcessor
{
    public $classKey='mxBackupRun'; public $languageTopics=['mxbackup:default']; public $objectType='mxbackup_run';
    public function checkPermissions(){return $this->modx->hasPermission('mxbackup_view');}
}
return 'mxBackupRunGetProcessor';
