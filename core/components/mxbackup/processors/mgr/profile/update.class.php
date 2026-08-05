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
        $current = $this->object->get('config_json');
        if (!is_array($current)) $current = json_decode((string)$current, true);
        if (!is_array($current)) $current = [];
        $corePath = $this->modx->getOption('mxbackup.core_path', null, MODX_CORE_PATH . 'components/mxbackup/');
        require_once $corePath . 'autoload.php';
        try {
            $config = (new \MxBackup\Core\Config\ProfileEditor())->update($current, $this->getProperties());
            $this->setProperty('config_json', $config);
        } catch (InvalidArgumentException $e) {
            $this->addFieldError('format', $e->getMessage());
        }
        $active = $this->getProperty('active');
        $this->setProperty('active', in_array($active, [true, 1, '1', 'true', 'on'], true) ? 1 : 0);
        $this->setProperty('editedon', time());
        return !$this->hasErrors();
    }
}
return 'mxBackupProfileUpdateProcessor';
