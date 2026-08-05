<?php

namespace MxBackup\Platform\Modx2;

use MxBackup\Core\Contract\ProfileRepositoryInterface;

final class ProfileRepository implements ProfileRepositoryInterface
{
    private $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    public function all()
    {
        $result = [];
        $query = $this->modx->newQuery('mxBackupProfile');
        $query->where(['active' => 1]);
        $query->sortby('name', 'ASC');
        foreach ($this->modx->getIterator('mxBackupProfile', $query) as $profile) {
            $config = $profile->get('config_json');
            if (!is_array($config)) {
                $config = json_decode((string)$config, true);
            }
            if (!is_array($config)) $config = [];
            $config['name'] = (string)$profile->get('name');
            $config['mode'] = (string)$profile->get('mode');
            foreach ($this->rules((int)$profile->get('id')) as $rule) {
                $targetType = (string)$rule['target_type'];
                $action = (string)$rule['action'];
                if (in_array($targetType, ['file', 'directory'], true) && in_array($action, ['include', 'exclude'], true)) {
                    $key = $action === 'include' ? 'include' : 'exclude';
                    $config['files'][$key][] = (string)$rule['target'];
                } elseif ($targetType === 'table' && in_array($action, ['include', 'exclude'], true)) {
                    $key = $action === 'include' ? 'include_tables' : 'exclude_tables';
                    $config['database'][$key][] = (string)$rule['target'];
                } else {
                    $config['masking']['rules'][] = $rule;
                }
            }
            $result[$config['name']] = $config;
        }
        return $result;
    }

    public function find($name)
    {
        $all = $this->all();
        return isset($all[$name]) ? $all[$name] : null;
    }

    private function rules($profileId)
    {
        $result = [];
        $query = $this->modx->newQuery('mxBackupRule');
        $query->where(['profile_id' => $profileId, 'active' => 1]);
        $query->sortby('priority', 'ASC');
        foreach ($this->modx->getIterator('mxBackupRule', $query) as $rule) {
            $result[] = $rule->toArray();
        }
        return $result;
    }
}
