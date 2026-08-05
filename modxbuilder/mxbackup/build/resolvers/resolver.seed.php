<?php

if ($transport->xpdo) {
    $modx =& $transport->xpdo;
    if (in_array($options[xPDOTransport::PACKAGE_ACTION], [xPDOTransport::ACTION_INSTALL, xPDOTransport::ACTION_UPGRADE], true)) {
        $corePath = $modx->getOption('mxbackup.core_path', null, $modx->getOption('core_path') . 'components/mxbackup/');
        require_once $corePath . 'autoload.php';
        $store = (new \MxBackup\Platform\Modx2\ProfileRepository($modx))->getStore();
        $prefix = (string) $modx->getOption('table_prefix', null, 'modx_');
        $profileTable = $prefix . 'mxbackup_profile';
        $ruleTable = $prefix . 'mxbackup_rule';
        $tables = [];
        $statement = $modx->query('SHOW TABLES');
        if ($statement) {
            foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
                $tables[] = (string) $row[0];
            }
            $statement->closeCursor();
        }

        $migrationOk = true;
        $legacyProfiles = [];
        if (in_array($profileTable, $tables, true)) {
            $statement = $modx->query("SELECT * FROM `{$profileTable}` ORDER BY `id` ASC");
            $legacyProfiles = $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
            if ($statement) $statement->closeCursor();
        }
        foreach ($legacyProfiles as $row) {
            $name = (string) $row['name'];
            if ($store->find($name)) {
                continue;
            }
            $profile = json_decode((string) $row['config_json'], true);
            if (!is_array($profile)) $profile = [];
            $profile = array_merge($profile, [
                'name' => $name,
                'description' => isset($row['description']) ? (string) $row['description'] : '',
                'mode' => isset($row['mode']) ? (string) $row['mode'] : 'custom',
                'active' => !empty($row['active']),
                'createdon' => isset($row['createdon']) ? (int) $row['createdon'] : time(),
                'editedon' => isset($row['editedon']) ? (int) $row['editedon'] : time(),
            ]);
            $profile['files'] = isset($profile['files']) && is_array($profile['files'])
                ? $profile['files'] : ['include' => ['*'], 'exclude' => []];
            $profile['database'] = isset($profile['database']) && is_array($profile['database'])
                ? $profile['database'] : ['include_tables' => ['*'], 'exclude_tables' => []];
            $profile['masking'] = isset($profile['masking']) && is_array($profile['masking'])
                ? $profile['masking'] : ['standard' => false, 'rules' => []];
            $profile['encryption'] = isset($profile['encryption']) && is_array($profile['encryption'])
                ? $profile['encryption'] : ['enabled' => false, 'password' => ''];
            $profile['masking']['rules'] = isset($profile['masking']['rules']) && is_array($profile['masking']['rules'])
                ? $profile['masking']['rules'] : [];

            if (in_array($ruleTable, $tables, true)) {
                $statement = $modx->query("SELECT * FROM `{$ruleTable}` WHERE `profile_id` = " . (int) $row['id'] . ' ORDER BY `priority` ASC, `id` ASC');
                $rules = $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
                if ($statement) $statement->closeCursor();
                foreach ($rules as $rule) {
                    $targetType = (string) $rule['target_type'];
                    $action = (string) $rule['action'];
                    if (in_array($targetType, ['file', 'directory'], true) && in_array($action, ['include', 'exclude'], true)) {
                        $profile['files'][$action][] = (string) $rule['target'];
                        $profile['files'][$action] = array_values(array_unique($profile['files'][$action]));
                    } elseif ($targetType === 'table' && in_array($action, ['include', 'exclude'], true)) {
                        $key = $action === 'include' ? 'include_tables' : 'exclude_tables';
                        $profile['database'][$key][] = (string) $rule['target'];
                        $profile['database'][$key] = array_values(array_unique($profile['database'][$key]));
                    } else {
                        $profile['masking']['rules'][] = [
                            'id' => (int) $rule['id'],
                            'target_type' => $targetType,
                            'target' => (string) $rule['target'],
                            'action' => $action,
                            'value' => $rule['value'],
                            'priority' => (int) $rule['priority'],
                            'active' => !empty($rule['active']),
                            'createdon' => (int) $rule['createdon'],
                            'editedon' => (int) $rule['editedon'],
                        ];
                    }
                }
            }
            try {
                $store->save($profile);
            } catch (Throwable $e) {
                $migrationOk = false;
                $modx->log(modX::LOG_LEVEL_ERROR, '[mxbackup] Не удалось перенести профиль ' . $name . ' в файл: ' . $e->getMessage());
            }
        }

        $defaults = [
            'prod' => [
                'name' => 'prod', 'mode' => 'prod', 'active' => true,
                'description' => 'Полный аварийный backup', 'format' => 'tar.gz',
                'encryption' => ['enabled' => false, 'password' => ''],
                'files' => ['include' => ['*'], 'exclude' => ['core/cache/', 'core/packages/', 'assets/cache/']],
                'database' => ['include_tables' => ['*'], 'exclude_tables' => []],
                'masking' => ['standard' => false, 'rules' => []],
            ],
            'dev' => [
                'name' => 'dev', 'mode' => 'dev', 'active' => true,
                'description' => 'Обезличенный backup для разработки', 'format' => 'tar.gz',
                'encryption' => ['enabled' => false, 'password' => ''],
                'files' => ['include' => ['*'], 'exclude' => ['core/cache/', 'core/packages/', 'core/config/', 'assets/cache/', 'assets/uploads/private/']],
                'database' => ['include_tables' => ['*'], 'exclude_tables' => []],
                'masking' => ['standard' => true, 'rules' => []],
            ],
        ];
        foreach ($defaults as $name => $profile) {
            if ($store->find($name)) continue;
            $profile['createdon'] = time();
            $profile['editedon'] = time();
            try {
                $store->save($profile);
            } catch (Throwable $e) {
                $migrationOk = false;
                $modx->log(modX::LOG_LEVEL_ERROR, '[mxbackup] Не удалось создать профиль ' . $name . ': ' . $e->getMessage());
            }
        }

        foreach ($legacyProfiles as $row) {
            if (!$store->find((string) $row['name'])) {
                $migrationOk = false;
            }
        }
        if ($migrationOk) {
            if (in_array($ruleTable, $tables, true)) $modx->exec("DROP TABLE `{$ruleTable}`");
            if (in_array($profileTable, $tables, true)) $modx->exec("DROP TABLE `{$profileTable}`");
        }
    }
}

return true;
