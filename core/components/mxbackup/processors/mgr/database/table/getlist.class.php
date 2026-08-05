<?php
class mxBackupDatabaseTableGetListProcessor extends modProcessor
{
    public function checkPermissions() { return $this->modx->hasPermission('mxbackup_view'); }

    public function process()
    {
        $profile = $this->modx->getObject('mxBackupProfile', (int)$this->getProperty('profile_id'));
        if (!$profile) return $this->failure($this->modx->lexicon('mxbackup_profile_not_found'));
        $config = $profile->get('config_json');
        if (!is_array($config)) $config = json_decode((string)$config, true);
        if (!is_array($config)) $config = [];
        $corePath = $this->modx->getOption('mxbackup.core_path', null, MODX_CORE_PATH . 'components/mxbackup/');
        require_once $corePath . 'autoload.php';
        $editor = new \MxBackup\Core\Config\ProfileEditor();
        $statement = $this->modx->query("SHOW TABLE STATUS WHERE Engine IS NOT NULL");
        if (!$statement) return $this->failure($this->modx->lexicon('mxbackup_tables_load_error'));
        $status = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        $available = [];
        foreach ($status as $row) $available[] = (string)$row['Name'];
        $selected = array_fill_keys($editor->selection($config, $available), true);
        $query = mb_strtolower(trim((string)$this->getProperty('query', '')), 'UTF-8');
        $includedOnly = (bool)$this->getProperty('included_only', false);
        $rows = [];
        foreach ($status as $row) {
            $name = (string)$row['Name'];
            if ($includedOnly && !isset($selected[$name])) continue;
            if ($query !== '' && mb_strpos(mb_strtolower($name, 'UTF-8'), $query, 0, 'UTF-8') === false) continue;
            $rows[] = [
                'name' => $name,
                'engine' => (string)$row['Engine'],
                'rows' => isset($row['Rows']) ? (int)$row['Rows'] : 0,
                'size' => (int)(isset($row['Data_length']) ? $row['Data_length'] : 0)
                    + (int)(isset($row['Index_length']) ? $row['Index_length'] : 0),
                'included' => isset($selected[$name]),
                'selection_mode' => $editor->selectionMode($config),
            ];
        }
        usort($rows, static function ($a, $b) { return strcmp($a['name'], $b['name']); });
        $total = count($rows);
        $start = max(0, (int)$this->getProperty('start', 0));
        $limit = max(0, (int)$this->getProperty('limit', 25));
        if ($limit > 0) $rows = array_slice($rows, $start, $limit);
        return $this->outputArray($rows, $total);
    }
}
return 'mxBackupDatabaseTableGetListProcessor';
