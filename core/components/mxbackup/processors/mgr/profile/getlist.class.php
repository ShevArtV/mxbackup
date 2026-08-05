<?php
class mxBackupProfileGetListProcessor extends modObjectGetListProcessor
{
    public $classKey = 'mxBackupProfile';
    public $languageTopics = ['mxbackup:default'];
    public $defaultSortField = 'name';
    public $defaultSortDirection = 'ASC';
    public $objectType = 'mxbackup_profile';
    public function checkPermissions() { return $this->modx->hasPermission('mxbackup_view'); }
    public function prepareRow(xPDOObject $object)
    {
        $row = $object->toArray();
        $config = $object->get('config_json');
        if (!is_array($config)) $config = json_decode((string)$config, true);
        if (!is_array($config)) $config = [];
        $row['format'] = isset($config['format']) ? $config['format'] : 'tar.gz';
        $row['file_include'] = implode("\n", isset($config['files']['include']) ? $config['files']['include'] : ['*']);
        $row['file_exclude'] = implode("\n", isset($config['files']['exclude']) ? $config['files']['exclude'] : []);
        $row['standard_masking'] = !empty($config['masking']['standard']);
        return $row;
    }
}
return 'mxBackupProfileGetListProcessor';
