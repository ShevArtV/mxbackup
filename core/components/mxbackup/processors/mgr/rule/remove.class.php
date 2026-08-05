<?php
class mxBackupRuleRemoveProcessor extends modObjectRemoveProcessor
{
    public $classKey='mxBackupRule'; public $languageTopics=['mxbackup:default']; public $objectType='mxbackup_rule';
    public function checkPermissions(){return $this->modx->hasPermission('mxbackup_manage');}
}
return 'mxBackupRuleRemoveProcessor';
