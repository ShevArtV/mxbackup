<?php

namespace MxBackup\Tests;

use MxBackup\Core\Archive\ArchiveWriter;
use MxBackup\Core\BackupRunner;
use MxBackup\Core\Config\ConfigResolver;
use MxBackup\Core\Config\Defaults;
use MxBackup\Core\RestoreRunner;
use MxBackup\Tests\Fake\FakeDatabase;
use MxBackup\Tests\Fake\FakePlatform;
use MxBackup\Tests\Fake\RecordingProgress;
use PHPUnit\Framework\TestCase;

/**
 * Индикация обещает пользователю, сколько работы осталось. Значит, проверять
 * надо не картинку, а то, что раннеры объявляют стадии в том же составе и
 * порядке, в каком их выполняют.
 */
final class RunnerProgressTest extends TestCase
{
    public function testBackupAnnouncesStagesItActuallyRuns()
    {
        if (!class_exists('ZipArchive')) self::markTestSkipped('ext-zip missing');
        $base = sys_get_temp_dir() . '/mxb-progress-' . bin2hex(random_bytes(5));
        $site = $base . '/site';
        $storage = $base . '/backups';
        mkdir($site, 0700, true);
        mkdir($storage, 0700, true);
        file_put_contents($site . '/index.php', '<?php echo "ok";');
        $platform = new FakePlatform($site, new FakeDatabase(['modx_site_content' => [['id' => 1]]]));
        $config = (new ConfigResolver())->resolve(Defaults::values(), [], [], [
            'storage_path' => $storage, 'format' => 'zip', 'profile' => 'prod',
        ], []);

        $progress = new RecordingProgress();
        (new BackupRunner($platform, $progress))->run($config, false);

        $expected = ['Сбор файлов', 'Подготовка базы', 'Дамп базы', 'Упаковка архива', 'Контрольная сумма'];
        self::assertSame($expected, $progress->planned);
        self::assertSame($expected, $progress->stages);
        self::assertSame(1, $progress->advanced['Сбор файлов']);
        self::assertSame(1, $progress->advanced['Дамп базы']);
        // Файл сайта плюс database.sql и манифест.
        self::assertSame(3, $progress->advanced['Упаковка архива']);
        self::assertGreaterThan(0, $progress->advanced['Контрольная сумма']);
        self::assertTrue($progress->finished);

        $this->removeTree($base);
    }

    public function testDryRunAnnouncesOnlyStagesBeforeArchiving()
    {
        $base = sys_get_temp_dir() . '/mxb-progress-dry-' . bin2hex(random_bytes(5));
        $site = $base . '/site';
        $storage = $base . '/backups';
        mkdir($site, 0700, true);
        mkdir($storage, 0700, true);
        file_put_contents($site . '/index.php', '<?php echo "ok";');
        $platform = new FakePlatform($site, new FakeDatabase(['modx_site_content' => [['id' => 1]]]));
        $config = (new ConfigResolver())->resolve(Defaults::values(), [], [], [
            'storage_path' => $storage, 'format' => 'zip', 'profile' => 'prod',
        ], []);

        $progress = new RecordingProgress();
        (new BackupRunner($platform, $progress))->run($config, true);

        self::assertSame(['Сбор файлов', 'Подготовка базы'], $progress->planned);
        self::assertSame(['Сбор файлов', 'Подготовка базы'], $progress->stages);

        $this->removeTree($base);
    }

    public function testRestoreAnnouncesStagesAndNestsSafetyBackup()
    {
        if (!class_exists('ZipArchive')) self::markTestSkipped('ext-zip missing');
        $base = sys_get_temp_dir() . '/mxb-progress-restore-' . bin2hex(random_bytes(5));
        $site = $base . '/site';
        $storage = $base . '/backups';
        $payload = $base . '/payload';
        mkdir($site, 0700, true);
        mkdir($storage, 0700);
        mkdir($payload, 0700);
        file_put_contents($site . '/current.txt', 'current');
        file_put_contents($payload . '/index.php', 'restored');
        $sql = "SET NAMES utf8mb4;\nCREATE TABLE `restored` (`id` int);\n";
        file_put_contents($payload . '/database.sql', $sql);
        file_put_contents($payload . '/manifest.json', json_encode([
            'schema' => 1, 'mxbackup_version' => '1.7.0-rc', 'modx_version' => '2.8.8-pl',
            'profile' => 'prod', 'mode' => 'prod', 'status' => 'payload_ready',
            'payload_checksums' => ['database.sql' => hash('sha256', $sql)],
        ]));
        $archive = $storage . '/source.zip';
        (new ArchiveWriter())->write($archive, 'zip', [[
            'absolute' => $payload . '/index.php', 'relative' => 'index.php', 'size' => 8,
        ]], $payload . '/database.sql', $payload . '/manifest.json', null);

        $platform = new FakePlatform($site, new FakeDatabase([]));
        $config = (new ConfigResolver())->resolve(Defaults::values(), [], [], [
            'storage_path' => $storage, 'format' => 'zip', 'profile' => 'prod',
        ], []);
        $progress = new RecordingProgress();
        $runner = new RestoreRunner($platform, null, $progress);
        $preflight = $runner->preflight($archive);
        $runner->restore($config, $archive, 'all', $preflight['confirmation']);

        $expected = ['Распаковка архива', 'Страховочная копия', 'Восстановление файлов', 'Импорт базы'];
        self::assertSame($expected, $progress->planned);
        self::assertSame($expected, $progress->stages);
        self::assertSame(1, $progress->advanced['Восстановление файлов']);
        self::assertSame(strlen($sql), $progress->advanced['Импорт базы']);
        self::assertTrue($progress->finished);

        // Страховочная копия — полноценный backup, и его стадии идут отдельной
        // вложенной лентой, а не подмешиваются в стадии восстановления.
        self::assertCount(1, $progress->children);
        self::assertSame('Сбор файлов', $progress->children[0]->stages[0]);
        self::assertTrue($progress->children[0]->finished);

        $this->removeTree($base);
    }

    public function testDatabaseOnlyRestoreSkipsFileStage()
    {
        if (!class_exists('ZipArchive')) self::markTestSkipped('ext-zip missing');
        $base = sys_get_temp_dir() . '/mxb-progress-db-' . bin2hex(random_bytes(5));
        $site = $base . '/site';
        $storage = $base . '/backups';
        $payload = $base . '/payload';
        mkdir($site, 0700, true);
        mkdir($storage, 0700);
        mkdir($payload, 0700);
        file_put_contents($payload . '/index.php', 'restored');
        $sql = "SET NAMES utf8mb4;\n";
        file_put_contents($payload . '/database.sql', $sql);
        file_put_contents($payload . '/manifest.json', json_encode([
            'schema' => 1, 'mxbackup_version' => '1.7.0-rc', 'modx_version' => '2.8.8-pl',
            'profile' => 'prod', 'mode' => 'prod', 'status' => 'payload_ready',
            'payload_checksums' => ['database.sql' => hash('sha256', $sql)],
        ]));
        $archive = $storage . '/source.zip';
        (new ArchiveWriter())->write($archive, 'zip', [[
            'absolute' => $payload . '/index.php', 'relative' => 'index.php', 'size' => 8,
        ]], $payload . '/database.sql', $payload . '/manifest.json', null);

        $platform = new FakePlatform($site, new FakeDatabase([]));
        $config = (new ConfigResolver())->resolve(Defaults::values(), [], [], [
            'storage_path' => $storage, 'format' => 'zip', 'profile' => 'prod',
        ], []);
        $progress = new RecordingProgress();
        $runner = new RestoreRunner($platform, null, $progress);
        $preflight = $runner->preflight($archive);
        $runner->restore($config, $archive, 'database', $preflight['confirmation']);

        self::assertSame(['Распаковка архива', 'Страховочная копия', 'Импорт базы'], $progress->planned);
        self::assertSame(['Распаковка архива', 'Страховочная копия', 'Импорт базы'], $progress->stages);

        $this->removeTree($base);
    }

    private function removeTree($base)
    {
        if (!is_dir($base)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($base);
    }
}
