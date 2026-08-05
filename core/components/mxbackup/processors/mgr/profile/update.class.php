<?php
class mxBackupProfileUpdateProcessor extends modObjectUpdateProcessor
{
    public $classKey = 'mxBackupProfile';
    public $languageTopics = ['mxbackup:default'];
    public $objectType = 'mxbackup_profile';
    public function checkPermissions() { return $this->modx->hasPermission('mxbackup_manage'); }
    public function beforeSet()
    {
        $name = trim((string)$this->getProperty('name'));
        if (!preg_match('/^[a-z0-9_-]+$/i', $name)) $this->addFieldError('name', $this->modx->lexicon('mxbackup_invalid_name'));
        $duplicate = $this->modx->getObject($this->classKey, ['name' => $name]);
        if ($duplicate && (int)$duplicate->get('id') !== (int)$this->getProperty('id')) $this->addFieldError('name', $this->modx->lexicon('mxbackup_name_exists'));
        $json = $this->getProperty('config_json', '{}');
        $decoded = is_array($json) ? $json : json_decode((string)$json, true);
        if (!is_array($decoded)) $this->addFieldError('config_json', $this->modx->lexicon('mxbackup_invalid_json'));
        $this->setProperty('config_json', $decoded ?: []); $this->setProperty('editedon', time());
        return !$this->hasErrors();
    }
}
return 'mxBackupProfileUpdateProcessor';
