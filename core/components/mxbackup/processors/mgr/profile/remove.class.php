<?php
class mxBackupProfileRemoveProcessor extends modObjectRemoveProcessor
{
    public $classKey = 'mxBackupProfile';
    public $languageTopics = ['mxbackup:default'];
    public $objectType = 'mxbackup_profile';
    public function checkPermissions() { return $this->modx->hasPermission('mxbackup_manage'); }
    public function beforeRemove()
    {
        if (in_array($this->object->get('name'), ['prod','dev'], true)) return $this->modx->lexicon('mxbackup_builtin_profile_remove');
        return parent::beforeRemove();
    }
}
return 'mxBackupProfileRemoveProcessor';
