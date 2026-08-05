<?php
class mxBackupProfileTablesUpdateProcessor extends modProcessor
{
    public function checkPermissions() { return $this->modx->hasPermission('mxbackup_manage'); }

    public function process()
    {
        $profile = $this->modx->getObject('mxBackupProfile', (int)$this->getProperty('profile_id'));
        if (!$profile) return $this->failure($this->modx->lexicon('mxbackup_profile_not_found'));
        $corePath = $this->modx->getOption('mxbackup.core_path', null, MODX_CORE_PATH . 'components/mxbackup/');
        require_once $corePath . 'autoload.php';
        $database = new \MxBackup\Platform\Modx2\DatabaseAdapter($this->modx);
        $config = $profile->get('config_json');
        if (!is_array($config)) $config = json_decode((string)$config, true);
        if (!is_array($config)) $config = [];
        $editor = new \MxBackup\Core\Config\ProfileEditor();
        $available = $database->listTables();
        $selected = $editor->selection($config, $available);
        $selectionMode = $editor->selectionMode($config);
        $operation = (string)$this->getProperty('operation', 'replace');
        if ($operation === 'toggle') {
            $table = (string)$this->getProperty('table');
            if (!in_array($table, $available, true)) return $this->failure($this->modx->lexicon('mxbackup_table_not_found'));
            $included = $this->boolean($this->getProperty('included'));
            $selected = array_values(array_diff($selected, [$table]));
            if ($included) $selected[] = $table;
        } elseif ($operation === 'set_all') {
            $selected = $this->boolean($this->getProperty('included')) ? $available : [];
        } elseif ($operation === 'mode') {
            $selectionMode = (string)$this->getProperty('selection_mode', $selectionMode);
        } elseif ($operation === 'replace') {
            $selected = json_decode((string)$this->getProperty('tables', '[]'), true);
            if (!is_array($selected)) return $this->failure($this->modx->lexicon('mxbackup_invalid_table_selection'));
            $selectionMode = (string)$this->getProperty('selection_mode', $selectionMode);
        } else {
            return $this->failure($this->modx->lexicon('mxbackup_invalid_table_selection'));
        }
        try {
            $config = $editor->applyTableSelection($config, $available, $selected, $selectionMode);
        } catch (InvalidArgumentException $e) {
            return $this->failure($e->getMessage());
        }
        $profile->set('config_json', $config);
        $profile->set('editedon', time());
        if (!$profile->save()) return $this->failure($this->modx->lexicon('mxbackup_profile_save_error'));
        return $this->success($this->modx->lexicon('mxbackup_tables_saved'));
    }

    private function boolean($value)
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }
}
return 'mxBackupProfileTablesUpdateProcessor';
