<?php
class mxBackupProfileTablesUpdateProcessor extends modProcessor
{
    public function checkPermissions() { return $this->modx->hasPermission('mxbackup_manage'); }

    public function process()
    {
        $corePath = $this->modx->getOption('mxbackup.core_path', null, MODX_CORE_PATH . 'components/mxbackup/');
        require_once $corePath . 'autoload.php';
        $store = (new \MxBackup\Platform\Modx2\ProfileRepository($this->modx))->getStore();
        $profileName = (string) $this->getProperty('profile_id');
        $config = $store->find($profileName);
        if (!$config) return $this->failure($this->modx->lexicon('mxbackup_profile_not_found'));
        $database = new \MxBackup\Platform\Modx2\DatabaseAdapter($this->modx);
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
            $matching = $editor->filterTables($available, $this->getProperty('query', ''));
            if ($this->boolean($this->getProperty('included'))) {
                $selected = array_values(array_unique(array_merge($selected, $matching)));
            } else {
                $selected = array_values(array_diff($selected, $matching));
            }
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
        $config['editedon'] = time();
        try {
            $store->save($config, $profileName);
        } catch (Throwable $e) {
            return $this->failure($e->getMessage());
        }
        return $this->success($this->modx->lexicon('mxbackup_tables_saved'));
    }

    private function boolean($value)
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }
}
return 'mxBackupProfileTablesUpdateProcessor';
