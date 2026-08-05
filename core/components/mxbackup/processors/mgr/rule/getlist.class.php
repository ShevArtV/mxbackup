<?php
class mxBackupRuleGetListProcessor extends modObjectGetListProcessor
{
    public $classKey='mxBackupRule'; public $languageTopics=['mxbackup:default']; public $defaultSortField='priority'; public $defaultSortDirection='ASC'; public $objectType='mxbackup_rule';
    public function checkPermissions(){return $this->modx->hasPermission('mxbackup_view');}
    public function prepareQueryBeforeCount(xPDOQuery $c){$c->leftJoin('mxBackupProfile','Profile');$c->select($this->modx->getSelectColumns('mxBackupRule','mxBackupRule'));$c->select(['profile_name'=>'Profile.name']);return $c;}
}
return 'mxBackupRuleGetListProcessor';
