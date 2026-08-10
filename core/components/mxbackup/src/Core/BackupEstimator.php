<?php

namespace MxBackup\Core;

use MxBackup\Core\Config\ConfigValidator;
use MxBackup\Core\Contract\PlatformInterface;
use MxBackup\Core\Files\FileCollector;
use MxBackup\Core\Files\FileRuleMatcher;
use MxBackup\Core\Storage\LocalStorage;
use MxBackup\Core\Storage\PathValidator;
use RuntimeException;

final class BackupEstimator
{
    private $platform;

    public function __construct(PlatformInterface $platform)
    {
        $this->platform = $platform;
    }

    public function estimate(array $config)
    {
        $errors = (new ConfigValidator())->validate($config);
        if ($errors) {
            throw new RuntimeException(implode('; ', $errors));
        }
        $profile = $config['profile'];
        $siteRoot = realpath($this->platform->getSiteRoot());
        if (!$siteRoot) {
            throw new RuntimeException('Корень сайта не существует.');
        }
        $storagePath = trim((string) (!empty($profile['storage_path']) ? $profile['storage_path'] : $config['storage_path']));
        if ($storagePath === '') {
            $storagePath = dirname($siteRoot) . DIRECTORY_SEPARATOR . 'backups';
        }
        $validator = new PathValidator();
        $storagePath = $validator->validateCandidate($storagePath, $siteRoot, !empty($config['allow_web_storage']));
        (new LocalStorage())->ensureDirectory($storagePath);
        $storagePath = $validator->validate($storagePath, $siteRoot, !empty($config['allow_web_storage']));

        $excludes = $profile['files']['exclude'];
        if (strpos($storagePath . DIRECTORY_SEPARATOR, $siteRoot . DIRECTORY_SEPARATOR) === 0) {
            $excludes[] = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($storagePath, strlen($siteRoot)), DIRECTORY_SEPARATOR)) . '/';
        }
        $collector = new FileCollector();
        $matcher = new FileRuleMatcher($profile['files']['include'], $excludes);
        $files = 0;
        $bytes = 0;
        foreach ($collector->collect($siteRoot, $matcher) as $file) {
            $files++;
            $bytes += $file['size'];
        }
        $free = disk_free_space($storagePath);
        $large = $bytes >= 1024 * 1024 * 1024 || $files >= 10000;
        $warnings = $collector->getWarnings();
        if ($large) {
            $warnings[] = 'Большой backup надёжнее запускать через CLI: HTTP-запрос админки зависит от лимитов веб-сервера и PHP-FPM.';
        }
        if ($free !== false && $free < $bytes) {
            $warnings[] = 'Свободного места меньше исходного объёма файлов; с учётом SQL-дампа архив может не поместиться.';
        }

        return [
            'profile' => $profile['name'],
            'format' => $profile['format'],
            'files' => $files,
            'file_bytes' => $bytes,
            'free_bytes' => $free === false ? null : (int) $free,
            'large' => $large,
            'warnings' => $warnings,
        ];
    }
}
