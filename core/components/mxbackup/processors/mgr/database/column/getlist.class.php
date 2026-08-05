<?php
class mxBackupDatabaseColumnGetListProcessor extends modProcessor
{
    public function checkPermissions() { return $this->modx->hasPermission('mxbackup_view'); }

    public function process()
    {
        $profileId = (string)$this->getProperty('profile_id');
        $table = (string)$this->getProperty('table');
        $corePath = $this->modx->getOption('mxbackup.core_path', null, MODX_CORE_PATH . 'components/mxbackup/');
        require_once $corePath . 'autoload.php';
        $config = (new \MxBackup\Platform\Modx2\ProfileRepository($this->modx))
            ->getStore()->find($profileId);
        if (!$config) return $this->failure($this->modx->lexicon('mxbackup_profile_not_found'));
        $database = new \MxBackup\Platform\Modx2\DatabaseAdapter($this->modx);
        if (!in_array($table, $database->listTables(), true)) {
            return $this->failure($this->modx->lexicon('mxbackup_table_not_found'));
        }
        $standard = !empty($config['masking']['standard']) ? \MxBackup\Core\Masking\StandardRules::rules() : [];
        usort($standard, static function ($a, $b) { return $a->getPriority() <=> $b->getPriority(); });
        $custom = isset($config['masking']['rules']) && is_array($config['masking']['rules'])
            ? array_values(array_filter($config['masking']['rules'], static function ($rule) {
                return !empty($rule['active']);
            }))
            : [];
        usort($custom, static function ($a, $b) {
            return (int) $a['priority'] <=> (int) $b['priority'];
        });

        $meta = $database->describeTable($table);
        $rows = [];
        $tableRule = $this->tableRule($table, $standard, $custom);
        if ($tableRule) {
            $rows[] = [
                'column' => '*', 'type' => '', 'nullable' => true,
                'action' => 'truncate', 'source' => $tableRule['source'],
                'rule_id' => $tableRule['rule_id'], 'target_type' => 'table',
                'target' => $table, 'json_path' => '', 'value' => null, 'priority' => $tableRule['priority'],
            ];
        }
        foreach ($meta as $column => $columnMeta) {
            $effective = $this->columnRule($table, $column, $standard, $custom);
            $rows[] = [
                'column' => $column,
                'type' => isset($columnMeta['Type']) ? $columnMeta['Type'] : '',
                'nullable' => isset($columnMeta['Null']) && strtoupper((string)$columnMeta['Null']) === 'YES',
                'action' => $effective ? $effective['action'] : '',
                'source' => $effective ? $effective['source'] : 'none',
                'rule_id' => $effective ? $effective['rule_id'] : 0,
                'target_type' => $effective ? $effective['target_type'] : 'column',
                'target' => $effective ? $effective['target'] : ($table . '.' . $column),
                'json_path' => $effective ? $effective['json_path'] : '',
                'value' => $effective ? $effective['value'] : null,
                'priority' => $effective ? $effective['priority'] : 0,
            ];
        }
        return $this->outputArray($rows, count($rows));
    }

    private function tableRule($table, array $standard, array $custom)
    {
        $result = null;
        foreach ($standard as $rule) {
            if ($rule->getAction() === 'truncate' && $rule->matches($table)) {
                $result = ['source'=>'standard','rule_id'=>0,'priority'=>$rule->getPriority()];
            }
        }
        foreach ($custom as $rule) {
            if ($rule['target_type'] === 'table' && $rule['action'] === 'truncate' && fnmatch($rule['target'], $table)) {
                $result = ['source'=>'custom','rule_id'=>(int)$rule['id'],'priority'=>(int)$rule['priority']];
            }
        }
        return $result;
    }

    private function columnRule($table, $column, array $standard, array $custom)
    {
        $result = null;
        foreach ($standard as $rule) {
            if ($rule->getAction() !== 'truncate' && $rule->matches($table, $column)) {
                $result = [
                    'action'=>$rule->getAction(),'source'=>'standard','rule_id'=>0,
                    'target_type'=>$rule->getJsonPath() === null ? 'column' : 'json_path',
                    'target'=>$rule->getTable() . '.' . $rule->getColumn(),
                    'json_path'=>$rule->getJsonPath() === null ? '' : $rule->getJsonPath(),
                    'value'=>$rule->getValue(),'priority'=>$rule->getPriority(),
                ];
            }
        }
        foreach ($custom as $rule) {
            if (!in_array($rule['target_type'], ['column','json_path'], true)) continue;
            $parts = explode('.', $rule['target'], $rule['target_type'] === 'json_path' ? 3 : 2);
            if (count($parts) < 2 || !fnmatch($parts[0], $table) || !fnmatch($parts[1], $column)) continue;
            $jsonPath = $rule['target_type'] === 'json_path' && count($parts) === 3 ? $parts[2] : '';
            $result = [
                'action'=>$rule['action'],'source'=>'custom','rule_id'=>(int)$rule['id'],
                'target_type'=>$rule['target_type'],'target'=>$rule['target'],'json_path'=>$jsonPath,
                'value'=>$rule['value'],'priority'=>(int)$rule['priority'],
            ];
        }
        return $result;
    }
}
return 'mxBackupDatabaseColumnGetListProcessor';
