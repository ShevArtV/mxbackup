<?php

namespace MxBackup\Core;

use MxBackup\Core\Config\ConfigValidator;
use MxBackup\Core\Config\ConfigResolver;
use MxBackup\Core\Config\Defaults;
use MxBackup\Core\Contract\PlatformInterface;
use MxBackup\Core\Progress\NullProgress;
use MxBackup\Core\Progress\ProgressInterface;
use MxBackup\Core\Report\RunReport;
use MxBackup\Core\Restore\ArchiveReader;
use MxBackup\Core\Restore\DatabaseImporter;
use MxBackup\Core\Restore\FileRestorer;
use MxBackup\Core\Storage\LocalStorage;
use MxBackup\Core\Storage\PathValidator;
use MxBackup\Core\Storage\RunLock;
use RuntimeException;

final class RestoreRunner
{
    private $platform;
    private $reader;
    private $storage;
    private $lock;
    private $safetyBackup;
    private $progress;

    public function __construct(PlatformInterface $platform, callable $safetyBackup = null, ProgressInterface $progress = null)
    {
        $this->platform = $platform;
        $this->reader = new ArchiveReader();
        $this->storage = new LocalStorage();
        $this->lock = new RunLock();
        $this->safetyBackup = $safetyBackup;
        $this->progress = $progress ?: new NullProgress();
    }

    public function preflight($archivePath, $password = null, $expectedChecksum = null)
    {
        $info = $this->reader->inspect($archivePath, $password);
        if ($expectedChecksum !== null && $expectedChecksum !== ''
            && !hash_equals(strtolower((string)$expectedChecksum), strtolower($info['archive_checksum']))) {
            throw new RuntimeException('Checksum архива не совпадает с историей запуска.');
        }
        $sourceMajor = $this->majorVersion(isset($info['manifest']['modx_version']) ? $info['manifest']['modx_version'] : '');
        $targetMajor = $this->majorVersion($this->platform->getPlatformVersion());
        if ($sourceMajor && $targetMajor && $sourceMajor !== $targetMajor) {
            throw new RuntimeException('Нельзя восстановить архив MODX ' . $sourceMajor . ' в MODX ' . $targetMajor . '.');
        }
        $info['warnings'] = [];
        $sourceVersion = isset($info['manifest']['modx_version']) ? (string)$info['manifest']['modx_version'] : 'unknown';
        if ($sourceVersion !== 'unknown' && $sourceVersion !== $this->platform->getPlatformVersion()) {
            $info['warnings'][] = 'Версия MODX в архиве (' . $sourceVersion . ') отличается от текущей ('
                . $this->platform->getPlatformVersion() . ').';
        }
        if (isset($info['manifest']['mode']) && $info['manifest']['mode'] === 'dev') {
            $info['warnings'][] = 'Архив содержит обезличенную development-базу данных.';
        }
        unset($info['archive_path']);
        return $info;
    }

    public function restore(array $config, $archivePath, $scope, $confirmation, $password = null, $expectedChecksum = null)
    {
        if (!in_array($scope, ['all', 'files', 'database'], true)) {
            throw new RuntimeException('Режим восстановления должен быть all, files или database.');
        }
        $safetyConfig = $this->buildSafetyConfig($config);
        $errors = (new ConfigValidator())->validate($safetyConfig);
        if ($errors) {
            throw new RuntimeException(implode('; ', $errors));
        }
        $started = $this->platform->now();
        $report = new RunReport($started);
        $workspace = null;
        $safetyArchive = null;
        $siteRoot = realpath($this->platform->getSiteRoot());
        if ($siteRoot === false) {
            throw new RuntimeException('Корень сайта не существует.');
        }

        try {
            $this->progress->plan($this->planStages($scope));
            $storagePath = $this->resolveStoragePath($safetyConfig, $siteRoot);
            $workspace = $this->storage->createWorkspace($storagePath);
            $this->progress->stage('Распаковка архива');
            $info = $this->reader->extract($archivePath, $workspace, $password, $this->progress);
            if ($expectedChecksum !== null && $expectedChecksum !== ''
                && !hash_equals(strtolower((string)$expectedChecksum), strtolower($info['archive_checksum']))) {
                throw new RuntimeException('Checksum архива не совпадает с историей запуска.');
            }
            if (!hash_equals($info['confirmation'], strtolower(trim((string)$confirmation)))) {
                throw new RuntimeException('Неверный код подтверждения восстановления.');
            }
            $this->assertCompatible($info);

            // Страховочная копия — это вложенный BackupRunner в том же каталоге
            // хранения. Он чистит осиротевшие временные артефакты, поэтому наш
            // рабочий каталог с уже распакованным архивом передаётся как
            // защищённый — иначе restore потеряет данные, которые восстанавливает.
            $safetyConfig['preserve_paths'] = [$workspace];
            // Распаковка большого архива занимает минуты, за которые сервер
            // базы успевает закрыть простаивающее соединение. Дальше идёт
            // страховочный backup, а он начинается с записи в историю —
            // 11.08.2026 восстановление на этом и падало.
            $this->platform->database()->keepAlive();
            $this->progress->stage('Страховочная копия');
            $safetyArchive = $this->createSafetyBackup($safetyConfig);
            $report->set('operation', 'restore');
            $report->set('scope', $scope);
            $report->set('source_archive', $info['archive_name']);
            $report->set('source_checksum', $info['archive_checksum']);
            $report->set('source_manifest', $info['manifest']);
            $report->set('safety_backup', $safetyArchive);
            $report->set('encrypted_source', (bool)$info['encrypted']);
            foreach ($this->preflight($archivePath, $password, $expectedChecksum)['warnings'] as $warning) {
                $report->warning($warning);
            }

            $this->lock->acquire($storagePath, isset($safetyConfig['lock_ttl_minutes']) ? $safetyConfig['lock_ttl_minutes'] : 720);

            // Артефакты, пережившие убитый извне запуск (SIGKILL минует finally):
            // среди них незамаскированный database.sql, поэтому чистим и здесь.
            // $workspace создан до блокировки — исключаем его из уборки.
            $orphans = $this->storage->purgeOrphans($storagePath, [$workspace]);
            if ($orphans) {
                $report->warning('Удалены незавершённые артефакты прошлого запуска: ' . implode(', ', $orphans));
            }

            $this->platform->log('warning', 'Запуск восстановления из резервной копии.', [
                'scope' => $scope,
                'archive' => $info['archive_name'],
                'archive_checksum' => $info['archive_checksum'],
                'safety_backup' => $safetyArchive,
            ]);

            if ($scope === 'all' || $scope === 'files') {
                $this->progress->stage('Восстановление файлов');
                $report->set('restored_files', (new FileRestorer())->restore($info['site_path'], $siteRoot, $this->progress));
            }
            if ($scope === 'all' || $scope === 'database') {
                // Раскладка файлов сайта — снова долгий простой перед потоком SQL.
                $this->platform->database()->keepAlive();
                $this->progress->stage('Импорт базы');
                $report->set('restored_database', (new DatabaseImporter($this->platform->database()))
                    ->import($info['database_path'], $this->progress));
            }
            $report->complete($this->platform->now());
            $this->saveHistory($info, $report->toArray(), $started);
            $this->platform->log('warning', 'Восстановление завершено.', [
                'scope' => $scope,
                'archive' => $info['archive_name'],
                'safety_backup' => $safetyArchive,
            ]);
            return new RestoreResult(true, $report->toArray());
        } catch (\Throwable $e) {
            $report->error($e->getMessage());
            $report->complete($this->platform->now());
            $this->platform->log('error', 'Ошибка восстановления: ' . $e->getMessage(), [
                'scope' => $scope,
                'archive' => basename((string)$archivePath),
                'safety_backup' => $safetyArchive,
            ]);
            throw $e;
        } finally {
            $this->progress->finish();
            $this->lock->release();
            if ($workspace) $this->storage->removeTree($workspace);
        }
    }

    /**
     * Стадии восстановления. Файлы и база — по scope: показывать «[4/5] Импорт
     * базы» там, где базу не трогают, значит врать про объём работы.
     *
     * @return string[]
     */
    private function planStages($scope)
    {
        $stages = ['Распаковка архива', 'Страховочная копия'];
        if ($scope === 'all' || $scope === 'files') {
            $stages[] = 'Восстановление файлов';
        }
        if ($scope === 'all' || $scope === 'database') {
            $stages[] = 'Импорт базы';
        }

        return $stages;
    }

    private function createSafetyBackup(array $config)
    {
        $safetyConfig = $config;
        $safetyConfig['mail'] = ['enabled' => false, 'to' => '', 'max_attachment_mb' => 0];
        $safetyConfig['retention'] = ['days' => 0, 'count' => 0];
        if ($this->safetyBackup) {
            $path = call_user_func($this->safetyBackup, $safetyConfig);
        } else {
            // Вложенный прогон печатает свои стадии с отступом и собственной
            // нумерацией — иначе шесть стадий backup смешались бы с пятью
            // стадиями restore.
            $result = (new BackupRunner($this->platform, $this->progress->nested()))->run($safetyConfig, false);
            $path = $result->getArchivePath();
        }
        if (!$path || !is_file($path)) {
            throw new RuntimeException('Не удалось создать страховочный backup; восстановление отменено.');
        }
        return $path;
    }

    private function buildSafetyConfig(array $config)
    {
        $safetyConfig = $config;
        $defaults = Defaults::values();
        $prod = isset($config['profiles']['prod']) && is_array($config['profiles']['prod'])
            ? $config['profiles']['prod']
            : [];
        $profile = (new ConfigResolver())->merge($defaults['profiles']['prod'], $prod);
        $profile['name'] = 'pre-restore';
        $profile['mode'] = 'prod';
        $profile['masking'] = ['standard' => false, 'rules' => []];
        if (empty($profile['storage_path'])) {
            $profile['storage_path'] = isset($config['storage_path']) ? $config['storage_path'] : '';
        }
        $safetyConfig['profile'] = $profile;
        return $safetyConfig;
    }

    private function resolveStoragePath(array $config, $siteRoot)
    {
        $profile = isset($config['profile']) && is_array($config['profile']) ? $config['profile'] : [];
        $path = trim((string)(isset($profile['storage_path']) ? $profile['storage_path'] : $config['storage_path']));
        if ($path === '') $path = dirname($siteRoot) . DIRECTORY_SEPARATOR . 'backups';
        $validator = new PathValidator();
        $path = $validator->validateCandidate($path, $siteRoot, !empty($config['allow_web_storage']));
        $this->storage->ensureDirectory($path);
        return $validator->validate($path, $siteRoot, !empty($config['allow_web_storage']));
    }

    private function assertCompatible(array $info)
    {
        $sourceMajor = $this->majorVersion(isset($info['manifest']['modx_version']) ? $info['manifest']['modx_version'] : '');
        $targetMajor = $this->majorVersion($this->platform->getPlatformVersion());
        if ($sourceMajor && $targetMajor && $sourceMajor !== $targetMajor) {
            throw new RuntimeException('Архив создан для другой основной версии MODX.');
        }
    }

    private function saveHistory(array $info, array $report, $started)
    {
        $this->platform->database()->keepAlive();
        $runId = $this->platform->runs()->start([
            'profile' => isset($info['manifest']['profile']) ? $info['manifest']['profile'] : 'restore',
            'mode' => isset($info['manifest']['mode']) ? $info['manifest']['mode'] : 'restore',
            'status' => 'running',
            'storage_path' => dirname($info['archive_path']),
            'startedon' => $started,
        ]);
        if (!$runId || !$this->platform->runs()->finish($runId, [
            'status' => $report['status'],
            'archive_path' => $info['archive_path'],
            'archive_size' => filesize($info['archive_path']) ?: 0,
            'archive_checksum' => $info['archive_checksum'],
            'manifest_json' => $info['manifest'],
            'report_json' => $report,
            'completedon' => $this->platform->now(),
        ])) {
            $this->platform->log('error', 'Восстановление завершено, но запись истории не сохранена.', [
                'archive' => $info['archive_name'],
            ]);
        }
    }

    private function majorVersion($version)
    {
        return preg_match('/^(\d+)/', (string)$version, $match) ? (int)$match[1] : 0;
    }
}
