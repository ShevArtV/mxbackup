<?php

namespace MxBackup\Core;

use MxBackup\Core\Archive\ArchiveWriter;
use MxBackup\Core\Config\ConfigValidator;
use MxBackup\Core\Contract\PlatformInterface;
use MxBackup\Core\Database\DatabaseDumper;
use MxBackup\Core\Database\TableSelector;
use MxBackup\Core\Delivery\MailDelivery;
use MxBackup\Core\Files\FileCollector;
use MxBackup\Core\Files\FileRuleMatcher;
use MxBackup\Core\Masking\Masker;
use MxBackup\Core\Masking\RuleFactory;
use MxBackup\Core\Masking\StandardRules;
use MxBackup\Core\Report\ManifestBuilder;
use MxBackup\Core\Report\RunReport;
use MxBackup\Core\Storage\LocalStorage;
use MxBackup\Core\Storage\PathValidator;
use MxBackup\Core\Storage\RetentionPolicy;
use MxBackup\Core\Storage\RunLock;
use RuntimeException;

final class BackupRunner
{
    const VERSION = '1.2.0-rc';

    private $platform;
    private $storage;
    private $validator;
    private $lock;

    public function __construct(PlatformInterface $platform)
    {
        $this->platform = $platform;
        $this->storage = new LocalStorage();
        $this->validator = new PathValidator();
        $this->lock = new RunLock();
    }

    public function run(array $config, $dryRun = false)
    {
        $started = $this->platform->now();
        $report = new RunReport($started);
        $profile = isset($config['profile']) && is_array($config['profile']) ? $config['profile'] : [];
        $profileName = isset($profile['name']) ? (string) $profile['name'] : 'unknown';
        $mode = isset($profile['mode']) ? (string) $profile['mode'] : 'unknown';
        $runId = 0;
        $workspace = null;
        $archivePath = null;
        $temporary = null;

        try {
            $errors = (new ConfigValidator())->validate($config);
            if ($errors) {
                throw new RuntimeException(implode('; ', $errors));
            }

            $siteRoot = realpath($this->platform->getSiteRoot());
            if (!$siteRoot) {
                throw new RuntimeException('Корень сайта не существует.');
            }
            $storagePath = trim((string)(isset($profile['storage_path']) ? $profile['storage_path'] : $config['storage_path']));
            if ($storagePath === '') {
                $storagePath = dirname($siteRoot) . DIRECTORY_SEPARATOR . 'backups';
            }
            $storagePath = $this->validator->validateCandidate($storagePath, $siteRoot, !empty($config['allow_web_storage']));
            $this->storage->ensureDirectory($storagePath);
            $storagePath = $this->validator->validate($storagePath, $siteRoot, !empty($config['allow_web_storage']));
            $this->lock->acquire($storagePath, isset($config['lock_ttl_minutes']) ? $config['lock_ttl_minutes'] : 720);

            $runId = $this->platform->runs()->start([
                'profile' => $profileName,
                'mode' => $mode,
                'status' => 'running',
                'storage_path' => $storagePath,
                'startedon' => $started,
            ]);
            if (!$runId) {
                throw new RuntimeException('Не удалось создать запись в истории backup.');
            }
            $this->platform->log('info', 'Запуск резервного копирования.', [
                'run_id' => $runId,
                'profile' => $profileName,
                'mode' => $mode,
                'dry_run' => (bool) $dryRun,
            ]);

            $fileExcludes = $profile['files']['exclude'];
            if (strpos($storagePath . DIRECTORY_SEPARATOR, $siteRoot . DIRECTORY_SEPARATOR) === 0) {
                $fileExcludes[] = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($storagePath, strlen($siteRoot)), DIRECTORY_SEPARATOR)) . '/';
            }
            $matcher = new FileRuleMatcher($profile['files']['include'], $fileExcludes);
            $collector = new FileCollector();
            $files = [];
            $fileBytes = 0;
            foreach ($collector->collect($siteRoot, $matcher) as $file) {
                $files[] = $file;
                $fileBytes += $file['size'];
            }
            foreach ($collector->getWarnings() as $warning) {
                $report->warning($warning);
            }
            $report->set('files', count($files));
            $report->set('file_bytes', $fileBytes);
            $report->set('files_excluded', $collector->getExcludedCount());

            $databaseConfig = $profile['database'];
            $tables = (new TableSelector())->select(
                $this->platform->database()->listTables(),
                isset($databaseConfig['include_tables']) ? $databaseConfig['include_tables'] : ['*'],
                isset($databaseConfig['exclude_tables']) ? $databaseConfig['exclude_tables'] : []
            );
            $report->set('tables', count($tables));
            $report->set('table_names', $tables);
            $report->set('dry_run', (bool)$dryRun);

            $rules = [];
            if (!empty($profile['masking']['standard'])) {
                $rules = StandardRules::rules();
            }
            $customRules = isset($profile['masking']['rules']) && is_array($profile['masking']['rules'])
                ? $profile['masking']['rules'] : [];
            $rules = array_merge($rules, (new RuleFactory())->createMany($customRules));
            $masker = $mode === 'dev' ? new Masker($rules, null, StandardRules::requiredColumns()) : null;
            $maskingPlan = [];
            $maskedColumns = 0;
            $truncatedTables = [];
            if ($masker) {
                foreach ($tables as $table) {
                    $plan = $masker->planTable($table, $this->platform->database()->describeTable($table));
                    if ($plan['truncated']) {
                        $truncatedTables[] = $table;
                    }
                    if ($plan['truncated'] || $plan['columns']) {
                        $maskingPlan[$table] = $plan;
                    }
                    $maskedColumns += count($plan['columns']);
                }
            }
            $report->set('masking_tables', $maskingPlan);
            $report->set('masked_columns', $maskedColumns);
            $report->set('truncated_tables', $truncatedTables);
            $encryption = isset($profile['encryption']) && is_array($profile['encryption'])
                ? $profile['encryption']
                : ['enabled' => false, 'password' => ''];
            $encrypted = !empty($encryption['enabled']);
            $report->set('encrypted', $encrypted);
            if ($encrypted) {
                $report->set('encryption_method', 'zip-aes-256');
            }

            if ($dryRun) {
                $report->complete($this->platform->now());
                if (!$this->platform->runs()->finish($runId, [
                    'status' => $report->toArray()['status'],
                    'report_json' => $report->toArray(),
                    'completedon' => $this->platform->now(),
                ])) {
                    throw new RuntimeException('Не удалось завершить запись в истории backup.');
                }
                $this->platform->log('info', 'Dry-run завершён.', [
                    'run_id' => $runId,
                    'profile' => $profileName,
                ]);
                return new BackupResult(true, null, $report->toArray());
            }

            $workspace = $this->storage->createWorkspace($storagePath);
            $sqlPath = $workspace . DIRECTORY_SEPARATOR . 'database.sql';
            $databaseReport = (new DatabaseDumper($this->platform->database(), $masker))->dump(
                $sqlPath,
                $tables,
                isset($config['batch_size']) ? (int)$config['batch_size'] : 500
            );
            foreach ($databaseReport['warnings'] as $warning) {
                $report->warning($warning);
            }
            $report->set('database', $databaseReport);

            $manifest = (new ManifestBuilder())->build([
                'mxbackup_version' => self::VERSION,
                'modx_version' => $this->platform->getPlatformVersion(),
                'profile' => $profileName,
                'mode' => $mode,
                'created_at' => $started,
                'site_root' => $siteRoot,
                'payload_checksums' => ['database.sql' => $databaseReport['checksum']],
            ], $report);
            $manifestPath = $workspace . DIRECTORY_SEPARATOR . 'mxbackup-manifest.json';
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $format = $profile['format'];
            $stamp = gmdate('Ymd-His', $started);
            $extension = $format === 'zip' ? 'zip' : 'tar.gz';
            $baseName = 'mxbackup-' . $this->safeName($profileName) . '-' . $stamp;
            $temporary = $storagePath . DIRECTORY_SEPARATOR . '.' . $baseName . '.part.' . $extension;
            $final = $storagePath . DIRECTORY_SEPARATOR . $baseName . '.' . $extension;
            if (is_file($final)) {
                $final = $storagePath . DIRECTORY_SEPARATOR . $baseName . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
            }
            (new ArchiveWriter())->write(
                $temporary,
                $format,
                $files,
                $sqlPath,
                $manifestPath,
                $encrypted ? (string) $encryption['password'] : null
            );
            $archivePath = $this->storage->finalize($temporary, $final);
            $archiveSize = filesize($archivePath) ?: 0;
            $archiveChecksum = hash_file('sha256', $archivePath);
            $report->set('archive_size', $archiveSize);
            $report->set('archive_checksum', $archiveChecksum);

            $mailResult = (new MailDelivery($this->platform->mailer()))->deliver(
                isset($config['mail']) ? $config['mail'] : [],
                $archivePath,
                $report->toArray()
            );
            if (!$mailResult->isSent() && !empty($config['mail']['enabled'])) {
                $report->warning($mailResult->getMessage());
            }
            $report->set('mail', $mailResult->toArray());
            $deleted = (new RetentionPolicy())->cleanup(
                $storagePath,
                isset($config['retention']['days']) ? (int)$config['retention']['days'] : 30,
                isset($config['retention']['count']) ? (int)$config['retention']['count'] : 10,
                $this->platform->now()
            );
            $report->set('retention_deleted', $deleted);
            $report->complete($this->platform->now());

            if (!$this->platform->runs()->finish($runId, [
                'status' => $report->toArray()['status'],
                'archive_path' => $archivePath,
                'archive_size' => $archiveSize,
                'archive_checksum' => $archiveChecksum,
                'manifest_json' => $manifest,
                'report_json' => $report->toArray(),
                'email_sent' => $mailResult->isSent(),
                'completedon' => $this->platform->now(),
            ])) {
                throw new RuntimeException('Не удалось завершить запись в истории backup.');
            }
            $this->platform->log('info', 'Резервная копия создана.', [
                'run_id' => $runId,
                'profile' => $profileName,
                'status' => $report->toArray()['status'],
                'archive_size' => $archiveSize,
                'encrypted' => $encrypted,
            ]);
            return new BackupResult(true, $archivePath, $report->toArray());
        } catch (\Throwable $e) {
            $report->error($e->getMessage());
            $report->complete($this->platform->now());
            if ($runId) {
                $this->platform->runs()->finish($runId, [
                    'status' => 'error',
                    'archive_path' => $archivePath,
                    'report_json' => $report->toArray(),
                    'error' => $e->getMessage(),
                    'completedon' => $this->platform->now(),
                ]);
            }
            $this->platform->log('error', $e->getMessage(), [
                'run_id' => $runId,
                'profile' => $profileName,
                'mode' => $mode,
            ]);
            throw $e;
        } finally {
            if ($workspace) {
                $this->storage->removeTree($workspace);
            }
            if ($temporary && is_file($temporary)) {
                @unlink($temporary);
            }
            $this->lock->release();
        }
    }

    private function safeName($value)
    {
        $value = preg_replace('/[^a-z0-9_-]+/i', '-', (string)$value);
        return trim($value, '-') ?: 'backup';
    }
}
