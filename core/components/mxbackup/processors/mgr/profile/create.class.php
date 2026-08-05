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
        $corePath = $this->modx->getOption('mxbackup.core_path', null, MODX_CORE_PATH . 'components/mxbackup/');
        require_once $corePath . 'autoload.php';
        try {
            $config = (new \MxBackup\Core\Config\ProfileEditor())->update([], $this->getProperties());
            $this->setProperty('config_json', $config);
        } catch (InvalidArgumentException $e) {
            $this->addFieldError('format', $e->getMessage());
        }
        $active = $this->getProperty('active');
        $this->setProperty('active', in_array($active, [true, 1, '1', 'true', 'on'], true) ? 1 : 0);
        $this->setProperty('createdon', time()); $this->setProperty('editedon', time());
        return !$this->hasErrors();
    }
}
return 'mxBackupProfileCreateProcessor';
