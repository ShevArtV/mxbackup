<?php
class mxBackupRuleCreateProcessor extends modObjectCreateProcessor
{
    public $classKey='mxBackupRule'; public $languageTopics=['mxbackup:default']; public $objectType='mxbackup_rule';
    public function checkPermissions(){return $this->modx->hasPermission('mxbackup_manage');}
    public function beforeSet(){return mxBackupRuleValidator::validate($this);}
}
class mxBackupRuleValidator
{
    public static function validate($processor){
        if (!$processor->modx->getCount('mxBackupProfile',(int)$processor->getProperty('profile_id'))) $processor->addFieldError('profile_id',$processor->modx->lexicon('mxbackup_profile_not_found'));
        $targetType = (string)$processor->getProperty('target_type');
        $action = (string)$processor->getProperty('rule_action');
        $processor->setProperty('action', $action);
        if (!in_array($targetType,['file','directory','table','column','json_path'],true)) $processor->addFieldError('target_type',$processor->modx->lexicon('mxbackup_invalid_target_type'));
        $allowed = [
            'file' => ['include','exclude'],
            'directory' => ['include','exclude'],
            'table' => ['include','exclude','truncate'],
            'column' => ['mask','hide','hash','replace'],
            'json_path' => ['mask','hide','hash','replace'],
        ];
        if (!isset($allowed[$targetType]) || !in_array($action,$allowed[$targetType],true)) $processor->addFieldError('action',$processor->modx->lexicon('mxbackup_invalid_action'));
        if (trim((string)$processor->getProperty('target'))==='') $processor->addFieldError('target',$processor->modx->lexicon('field_required'));
        if ($targetType === 'column' && substr_count((string)$processor->getProperty('target'), '.') < 1) $processor->addFieldError('target',$processor->modx->lexicon('mxbackup_invalid_column_target'));
        if ($targetType === 'json_path' && substr_count((string)$processor->getProperty('target'), '.') < 2) $processor->addFieldError('target',$processor->modx->lexicon('mxbackup_invalid_json_target'));
        if ($action === 'replace' && $processor->getProperty('value') === null) $processor->addFieldError('value',$processor->modx->lexicon('field_required'));
        $criteria = [
            'profile_id' => (int)$processor->getProperty('profile_id'),
            'target_type' => (string)$processor->getProperty('target_type'),
            'target' => trim((string)$processor->getProperty('target')),
        ];
        $duplicate = $processor->modx->getObject('mxBackupRule', $criteria);
        if ($duplicate && (int)$duplicate->get('id') !== (int)$processor->getProperty('id')) {
            $processor->addFieldError('target', $processor->modx->lexicon('mxbackup_rule_exists'));
        }
        $processor->setProperty('createdon',time());$processor->setProperty('editedon',time());return !$processor->hasErrors();
    }
}
return 'mxBackupRuleCreateProcessor';
