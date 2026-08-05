<?php

namespace MxBackup\Platform\Modx2;

use MxBackup\Core\Contract\PlatformInterface;

final class Modx2Platform implements PlatformInterface
{
    private $modx;
    private $database;
    private $profiles;
    private $runs;
    private $mailer;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
        $corePath = $modx->getOption('mxbackup.core_path', null, MODX_CORE_PATH . 'components/mxbackup/');
        $modx->addPackage('mxbackup', $corePath . 'model/');
    }

    public function getOption($key, $default = null) { return $this->modx->getOption($key, null, $default); }
    public function getSiteRoot() { return $this->modx->getOption('base_path', null, MODX_BASE_PATH); }
    public function getCorePath() { return $this->modx->getOption('core_path', null, MODX_CORE_PATH); }
    public function getPlatformVersion() { return $this->modx->getVersionData()['full_version']; }
    public function now() { return time(); }

    public function log($level, $message, array $context = [])
    {
        $logger = isset($this->modx->mxlogger) && is_object($this->modx->mxlogger)
            ? $this->modx->mxlogger
            : null;
        if (!$logger) {
            try {
                $logger = $this->modx->getService(
                    'mxlogger',
                    'mxLogger',
                    MODX_CORE_PATH . 'components/mxlogger/model/mxlogger/'
                );
            } catch (\Throwable $e) {
                $logger = null;
            }
        }
        if ($logger && method_exists($logger, 'log')) {
            $options = ['skip_classes' => [__CLASS__]];
            if (!empty($context['run_id'])) {
                $options['process_uid'] = 'mxbackup_' . (int) $context['run_id'];
            }
            try {
                $logger->log(['mxbackup', 'backup'], $level, (string) $message, $context, $options);
                return;
            } catch (\Throwable $e) {
                // mxLogger is optional. A broken service must not hide the original event.
            }
        }

        $levels = ['debug' => \modX::LOG_LEVEL_DEBUG, 'info' => \modX::LOG_LEVEL_INFO, 'warning' => \modX::LOG_LEVEL_WARN, 'error' => \modX::LOG_LEVEL_ERROR];
        $suffix = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $this->modx->log(isset($levels[$level]) ? $levels[$level] : \modX::LOG_LEVEL_ERROR, '[mxbackup] ' . $message . $suffix);
    }

    public function database() { return $this->database ?: $this->database = new DatabaseAdapter($this->modx); }
    public function profiles() { return $this->profiles ?: $this->profiles = new ProfileRepository($this->modx); }
    public function runs() { return $this->runs ?: $this->runs = new RunRepository($this->modx); }
    public function mailer() { return $this->mailer ?: $this->mailer = new Mailer($this->modx); }
    public function getModx() { return $this->modx; }
}
