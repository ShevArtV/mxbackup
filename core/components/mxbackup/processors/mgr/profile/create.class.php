<?php
class mxBackupProfileCreateProcessor extends modObjectCreateProcessor
{
    public $classKey = 'mxBackupProfile';
    public $languageTopics = ['mxbackup:default'];
    public $objectType = 'mxbackup_profile';
    public function checkPermissions() { return $this->modx->hasPermission('mxbackup_manage'); }
    public function beforeSet()
    {
        $name = trim((string)$this->getProperty('name'));
        if (!preg_match('/^[a-z0-9_-]+$/i', $name)) $this->addFieldError('name', $this->modx->lexicon('mxbackup_invalid_name'));
        if ($this->modx->getCount($this->classKey, ['name' => $name])) $this->addFieldError('name', $this->modx->lexicon('mxbackup_name_exists'));
        $json = trim((string)$this->getProperty('config_json', '{}'));
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) $this->addFieldError('config_json', $this->modx->lexicon('mxbackup_invalid_json'));
        $this->setProperty('config_json', $decoded ?: []);
        $this->setProperty('createdon', time()); $this->setProperty('editedon', time());
        return !$this->hasErrors();
    }
}
return 'mxBackupProfileCreateProcessor';
