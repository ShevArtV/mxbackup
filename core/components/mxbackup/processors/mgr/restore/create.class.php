<?php

class mxBackupRestoreCreateProcessor extends modProcessor
{
    public function checkPermissions() { return $this->modx->hasPermission('mxbackup_restore'); }

    public function process()
    {
        set_time_limit(0);
        $run = $this->modx->getObject('mxBackupRun', (int)$this->getProperty('id'));
        if (!$run) return $this->failure('Запуск backup не найден.');
        $path = (string)$run->get('archive_path');
        if ($path === '' || !is_file($path)) return $this->failure('Архив из истории не найден на диске.');
        $corePath = $this->modx->getOption('mxbackup.core_path', null, MODX_CORE_PATH . 'components/mxbackup/');
        require_once $corePath . 'autoload.php';
        try {
            $config = \MxBackup\Bootstrap::config($this->modx, ['profile' => 'prod']);
            $result = \MxBackup\Bootstrap::restoreRunner($this->modx)->restore(
                $config,
                $path,
                (string)$this->getProperty('scope', 'all'),
                (string)$this->getProperty('confirmation', ''),
                (string)$this->getProperty('password', ''),
                (string)$run->get('archive_checksum')
            );
            return $this->success('Восстановление завершено.', ['report' => $result->getReport()]);
        } catch (Throwable $e) {
            return $this->failure($e->getMessage());
        }
    }
}

return 'mxBackupRestoreCreateProcessor';
