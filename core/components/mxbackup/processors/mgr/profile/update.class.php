<?php

require_once __DIR__ . '/store.php';

class mxBackupProfileUpdateProcessor extends modProcessor
{
    public function checkPermissions()
    {
        return $this->modx->hasPermission('mxbackup_manage');
    }

    public function process()
    {
        $originalName = trim((string) $this->getProperty('id'));
        $name = trim((string) $this->getProperty('name'));
        if (!preg_match('/^[a-z0-9_-]+$/i', $name)) {
            return $this->failure($this->modx->lexicon('mxbackup_invalid_name'));
        }
        if (in_array($originalName, ['prod', 'dev'], true) && $name !== $originalName) {
            return $this->failure($this->modx->lexicon('mxbackup_builtin_profile_rename'));
        }
        try {
            $store = mxBackupProfileStoreHelper::store($this->modx);
            $current = $store->find($originalName);
            if (!$current) {
                return $this->failure($this->modx->lexicon('mxbackup_profile_not_found'));
            }
            if ($name !== $originalName && $store->find($name)) {
                return $this->failure($this->modx->lexicon('mxbackup_name_exists'));
            }
            $profile = (new \MxBackup\Core\Config\ProfileEditor())->update($current, $this->getProperties());
            $profile['name'] = $name;
            $profile['description'] = trim((string) $this->getProperty('description', ''));
            $profile['mode'] = (string) $this->getProperty('mode', 'custom');
            $profile['active'] = mxBackupProfileStoreHelper::boolean($this->getProperty('active'));
            $profile['editedon'] = time();
            $profile = $store->save($profile, $originalName);
            return $this->success('', mxBackupProfileStoreHelper::row($profile));
        } catch (Throwable $e) {
            return $this->failure($e->getMessage());
        }
    }
}

return 'mxBackupProfileUpdateProcessor';
