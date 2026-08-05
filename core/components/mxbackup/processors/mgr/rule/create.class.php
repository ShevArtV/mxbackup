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
        if (!in_array($processor->getProperty('target_type'),['file','directory','table','column','json_path'],true)) $processor->addFieldError('target_type',$processor->modx->lexicon('mxbackup_invalid_target_type'));
        if (!in_array($processor->getProperty('action'),['include','exclude','mask','hide','truncate','hash','replace'],true)) $processor->addFieldError('action',$processor->modx->lexicon('mxbackup_invalid_action'));
        if (trim((string)$processor->getProperty('target'))==='') $processor->addFieldError('target',$processor->modx->lexicon('field_required'));
        $processor->setProperty('createdon',time());$processor->setProperty('editedon',time());return !$processor->hasErrors();
    }
}
return 'mxBackupRuleCreateProcessor';
