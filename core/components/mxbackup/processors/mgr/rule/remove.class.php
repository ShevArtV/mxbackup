<?php

require_once dirname(__DIR__) . '/profile/store.php';

class mxBackupRuleRemoveProcessor extends modProcessor
{
    public function checkPermissions()
    {
        return $this->modx->hasPermission('mxbackup_manage');
    }

    public function process()
    {
        try {
            $store = mxBackupProfileStoreHelper::store($this->modx);
            $profileName = trim((string) $this->getProperty('profile_id'));
            if (!$store->removeRule($profileName, (int) $this->getProperty('id'))) {
                return $this->failure($this->modx->lexicon('mxbackup_rule_not_found'));
            }
            return $this->success();
        } catch (Throwable $e) {
            return $this->failure($e->getMessage());
        }
    }
}

return 'mxBackupRuleRemoveProcessor';
