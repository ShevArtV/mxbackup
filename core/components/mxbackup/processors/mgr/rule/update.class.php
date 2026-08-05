<?php
require_once __DIR__ . '/create.class.php';
class mxBackupRuleUpdateProcessor extends modObjectUpdateProcessor
{
    public $classKey='mxBackupRule'; public $languageTopics=['mxbackup:default']; public $objectType='mxbackup_rule';
    public function checkPermissions(){return $this->modx->hasPermission('mxbackup_manage');}
    public function beforeSet(){$ok=mxBackupRuleValidator::validate($this);$this->setProperty('editedon',time());return $ok;}
}
return 'mxBackupRuleUpdateProcessor';
