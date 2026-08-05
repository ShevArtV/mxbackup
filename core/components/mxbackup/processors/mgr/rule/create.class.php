<?php

require_once dirname(__DIR__) . '/profile/store.php';

class mxBackupRuleCreateProcessor extends modProcessor
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
            $rule = mxBackupRuleValidator::data($this, $store, $profileName);
            $rule = $store->addRule($profileName, $rule);
            return $this->success('', $rule);
        } catch (Throwable $e) {
            return $this->failure($e->getMessage());
        }
    }
}

class mxBackupRuleValidator
{
    public static function data(modProcessor $processor, $store, $profileName, $excludeId = 0)
    {
        $profile = $store->find($profileName);
        if (!$profile) {
            throw new InvalidArgumentException($processor->modx->lexicon('mxbackup_profile_not_found'));
        }
        $targetType = (string) $processor->getProperty('target_type');
        $action = (string) $processor->getProperty('rule_action');
        $target = trim((string) $processor->getProperty('target'));
        $allowed = [
            'file' => ['include', 'exclude'],
            'directory' => ['include', 'exclude'],
            'table' => ['include', 'exclude', 'truncate'],
            'column' => ['mask', 'hide', 'hash', 'replace'],
            'json_path' => ['mask', 'hide', 'hash', 'replace'],
        ];
        if (!isset($allowed[$targetType])) {
            throw new InvalidArgumentException($processor->modx->lexicon('mxbackup_invalid_target_type'));
        }
        if (!in_array($action, $allowed[$targetType], true)) {
            throw new InvalidArgumentException($processor->modx->lexicon('mxbackup_invalid_action'));
        }
        if ($target === '') {
            throw new InvalidArgumentException($processor->modx->lexicon('field_required'));
        }
        if ($targetType === 'column' && substr_count($target, '.') < 1) {
            throw new InvalidArgumentException($processor->modx->lexicon('mxbackup_invalid_column_target'));
        }
        if ($targetType === 'json_path' && substr_count($target, '.') < 2) {
            throw new InvalidArgumentException($processor->modx->lexicon('mxbackup_invalid_json_target'));
        }
        if ($action === 'replace' && $processor->getProperty('value') === null) {
            throw new InvalidArgumentException($processor->modx->lexicon('field_required'));
        }
        $rules = isset($profile['masking']['rules']) && is_array($profile['masking']['rules'])
            ? $profile['masking']['rules']
            : [];
        foreach ($rules as $existing) {
            if ((int) (isset($existing['id']) ? $existing['id'] : 0) === (int) $excludeId) {
                continue;
            }
            if ((string) $existing['target_type'] === $targetType && (string) $existing['target'] === $target) {
                throw new InvalidArgumentException($processor->modx->lexicon('mxbackup_rule_exists'));
            }
        }
        return [
            'target_type' => $targetType,
            'target' => $target,
            'action' => $action,
            'value' => $processor->getProperty('value'),
            'priority' => (int) $processor->getProperty('priority', 100),
            'active' => mxBackupProfileStoreHelper::boolean($processor->getProperty('active', 1)),
        ];
    }
}

return 'mxBackupRuleCreateProcessor';
