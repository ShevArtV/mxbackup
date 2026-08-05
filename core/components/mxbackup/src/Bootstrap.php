<?php

namespace MxBackup;

use MxBackup\Core\BackupRunner;
use MxBackup\Core\RestoreRunner;
use MxBackup\Core\Config\ConfigLoader;
use MxBackup\Core\Config\ConfigResolver;
use MxBackup\Core\Config\Defaults;
use MxBackup\Platform\Modx2\Modx2Platform;

final class Bootstrap
{
    public static function platform(\modX $modx)
    {
        return new Modx2Platform($modx);
    }

    public static function config(\modX $modx, array $cli = [])
    {
        $platform = self::platform($modx);
        $system = [
            'storage_path' => $platform->getOption('mxbackup.storage_path', ''),
            'config_path' => $platform->getOption('mxbackup.config_path', ''),
            'default_profile' => $platform->getOption('mxbackup.default_profile', 'prod'),
            'format' => $platform->getOption('mxbackup.archive_format', 'tar.gz'),
            'mail' => [
                'enabled' => (bool)$platform->getOption('mxbackup.mail_enabled', false),
                'to' => $platform->getOption('mxbackup.mail_to', ''),
                'max_attachment_mb' => (float)$platform->getOption('mxbackup.mail_max_attachment_mb', 10),
            ],
            'retention' => [
                'days' => (int)$platform->getOption('mxbackup.retention_days', 30),
                'count' => (int)$platform->getOption('mxbackup.retention_count', 10),
            ],
            'lock_ttl_minutes' => (int)$platform->getOption('mxbackup.lock_ttl_minutes', 720),
        ];

        $configPath = isset($cli['config_path']) && $cli['config_path'] !== ''
            ? $cli['config_path']
            : ($system['config_path'] ?: $platform->getCorePath() . 'config/mxbackup.php');
        $file = is_file($configPath) ? (new ConfigLoader())->load($configPath) : [];
        unset($cli['config_path']);

        return (new ConfigResolver())->resolve(
            Defaults::values(),
            $system,
            $platform->profiles()->all(),
            $file,
            $cli
        );
    }

    public static function runner(\modX $modx)
    {
        return new BackupRunner(self::platform($modx));
    }

    public static function restoreRunner(\modX $modx)
    {
        return new RestoreRunner(self::platform($modx));
    }
}
