<?php

class mxBackupRestorePreflightProcessor extends modProcessor
{
    public function checkPermissions() { return $this->modx->hasPermission('mxbackup_restore'); }

    public function process()
    {
        $run = $this->modx->getObject('mxBackupRun', (int)$this->getProperty('id'));
        if (!$run) return $this->failure('Запуск backup не найден.');
        $path = (string)$run->get('archive_path');
        if ($path === '' || !is_file($path)) return $this->failure('Архив из истории не найден на диске.');
        $corePath = $this->modx->getOption('mxbackup.core_path', null, MODX_CORE_PATH . 'components/mxbackup/');
        require_once $corePath . 'autoload.php';
        try {
            $info = \MxBackup\Bootstrap::restoreRunner($this->modx)->preflight(
                $path,
                (string)$this->getProperty('password', ''),
                (string)$run->get('archive_checksum')
            );
            return $this->success('Preflight пройден.', $info);
        } catch (Throwable $e) {
            return $this->failure($e->getMessage());
        }
    }
}

return 'mxBackupRestorePreflightProcessor';
