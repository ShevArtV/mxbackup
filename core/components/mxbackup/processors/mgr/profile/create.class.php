<?php

require_once __DIR__ . '/store.php';

class mxBackupProfileCreateProcessor extends modProcessor
{
    public function checkPermissions()
    {
        return $this->modx->hasPermission('mxbackup_manage');
    }

    public function process()
    {
        $name = trim((string) $this->getProperty('name'));
        if (!preg_match('/^[a-z0-9_-]+$/i', $name)) {
            return $this->failure($this->modx->lexicon('mxbackup_invalid_name'));
        }
        try {
            $store = mxBackupProfileStoreHelper::store($this->modx);
            if ($store->find($name)) {
                return $this->failure($this->modx->lexicon('mxbackup_name_exists'));
            }
            $profile = (new \MxBackup\Core\Config\ProfileEditor())->update([], $this->getProperties());
            $profile = array_merge($profile, [
                'name' => $name,
                'description' => trim((string) $this->getProperty('description', '')),
                'mode' => (string) $this->getProperty('mode', 'custom'),
                'active' => mxBackupProfileStoreHelper::boolean($this->getProperty('active')),
                'createdon' => time(),
                'editedon' => time(),
            ]);
            $profile = $store->save($profile);
            return $this->success('', mxBackupProfileStoreHelper::row($profile));
        } catch (Throwable $e) {
            return $this->failure($e->getMessage());
        }
    }
}

return 'mxBackupProfileCreateProcessor';
