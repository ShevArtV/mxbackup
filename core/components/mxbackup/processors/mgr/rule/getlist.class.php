<?php

require_once dirname(__DIR__) . '/profile/store.php';

class mxBackupRuleGetListProcessor extends modProcessor
{
    public function checkPermissions()
    {
        return $this->modx->hasPermission('mxbackup_view');
    }

    public function process()
    {
        try {
            $profileFilter = trim((string) $this->getProperty('profile_id', ''));
            $rows = [];
            foreach (mxBackupProfileStoreHelper::store($this->modx)->all() as $profileName => $profile) {
                if ($profileFilter !== '' && $profileFilter !== $profileName) {
                    continue;
                }
                $rules = isset($profile['masking']['rules']) && is_array($profile['masking']['rules'])
                    ? $profile['masking']['rules']
                    : [];
                foreach ($rules as $rule) {
                    $rule['profile_id'] = $profileName;
                    $rule['profile_name'] = $profileName;
                    $rows[] = $rule;
                }
            }
            usort($rows, static function ($a, $b) {
                $profile = strcmp((string) $a['profile_name'], (string) $b['profile_name']);
                return $profile !== 0 ? $profile : ((int) $a['priority'] <=> (int) $b['priority']);
            });
            $total = count($rows);
            $start = max(0, (int) $this->getProperty('start', 0));
            $limit = max(0, (int) $this->getProperty('limit', 20));
            if ($limit > 0) {
                $rows = array_slice($rows, $start, $limit);
            }
            return $this->outputArray($rows, $total);
        } catch (Throwable $e) {
            return $this->failure($e->getMessage());
        }
    }
}

return 'mxBackupRuleGetListProcessor';
