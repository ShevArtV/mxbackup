<?php

require_once __DIR__ . '/store.php';

class mxBackupProfileGetListProcessor extends modProcessor
{
    public function checkPermissions()
    {
        return $this->modx->hasPermission('mxbackup_view');
    }

    public function process()
    {
        try {
            $rows = [];
            foreach (mxBackupProfileStoreHelper::store($this->modx)->all() as $profile) {
                $rows[] = mxBackupProfileStoreHelper::row($profile);
            }
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

return 'mxBackupProfileGetListProcessor';
