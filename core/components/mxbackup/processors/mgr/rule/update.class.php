<?php

require_once __DIR__ . '/create.class.php';

class mxBackupRuleUpdateProcessor extends modProcessor
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
            $ruleId = (int) $this->getProperty('id');
            if (!$store->findRule($profileName, $ruleId)) {
                return $this->failure($this->modx->lexicon('mxbackup_rule_not_found'));
            }
            $rule = mxBackupRuleValidator::data($this, $store, $profileName, $ruleId);
            $rule = $store->updateRule($profileName, $ruleId, $rule);
            return $this->success('', $rule);
        } catch (Throwable $e) {
            return $this->failure($e->getMessage());
        }
    }
}

return 'mxBackupRuleUpdateProcessor';
