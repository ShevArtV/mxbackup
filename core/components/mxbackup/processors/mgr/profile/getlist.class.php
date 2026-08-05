<?php
class mxBackupProfileGetListProcessor extends modObjectGetListProcessor
{
    public $classKey = 'mxBackupProfile';
    public $languageTopics = ['mxbackup:default'];
    public $defaultSortField = 'name';
    public $defaultSortDirection = 'ASC';
    public $objectType = 'mxbackup_profile';
    public function checkPermissions() { return $this->modx->hasPermission('mxbackup_view'); }
}
return 'mxBackupProfileGetListProcessor';
