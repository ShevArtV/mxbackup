<?php

require_once __DIR__ . '/store.php';

class mxBackupProfileRemoveProcessor extends modProcessor
{
    public function checkPermissions()
    {
        return $this->modx->hasPermission('mxbackup_manage');
    }

    public function process()
    {
        $name = trim((string) $this->getProperty('id'));
        if (in_array($name, ['prod', 'dev'], true)) {
            return $this->failure($this->modx->lexicon('mxbackup_builtin_profile_remove'));
        }
        try {
            $store = mxBackupProfileStoreHelper::store($this->modx);
            if (!$store->find($name)) {
                return $this->failure($this->modx->lexicon('mxbackup_profile_not_found'));
            }
            if (!$store->remove($name)) {
                return $this->failure($this->modx->lexicon('mxbackup_profile_save_error'));
            }
            return $this->success();
        } catch (Throwable $e) {
            return $this->failure($e->getMessage());
        }
    }
}

return 'mxBackupProfileRemoveProcessor';
