<?php

namespace MxBackup\Tests;

use MxBackup\Core\BackupRunner;
use MxBackup\Core\Config\ConfigResolver;
use MxBackup\Core\Config\Defaults;
use MxBackup\Tests\Fake\FakeDatabase;
use MxBackup\Tests\Fake\FakePlatform;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class BackupRunnerTest extends TestCase
{
    public function testCreatesDevelopmentArchiveWithoutOriginalPii()
    {
        if (!class_exists('ZipArchive')) self::markTestSkipped('ext-zip missing');
        $base = sys_get_temp_dir() . '/mxb-run-' . bin2hex(random_bytes(5));
        $site = $base . '/site'; $storage = $base . '/backups';
        mkdir($site, 0700, true); mkdir($storage, 0700, true);
        file_put_contents($site . '/index.php', '<?php echo "ok";');
        $database = new FakeDatabase(['modx_contacts' => [['id' => 1, 'email' => 'person@example.com', 'title' => 'Keep me']]]);
        $platform = new FakePlatform($site, $database);
        $config = (new ConfigResolver())->resolve(Defaults::values(), [], [], [
            'storage_path' => $storage, 'format' => 'zip', 'profile' => 'dev',
            'profiles' => ['dev' => [
                'encryption' => ['enabled' => true, 'password' => 'integration-test-password'],
            ]],
        ], []);
        $result = (new BackupRunner($platform))->run($config, false);
        self::assertTrue($result->isSuccess());
        self::assertFileExists($result->getArchivePath());

        $zip = new ZipArchive(); $zip->open($result->getArchivePath());
        self::assertFalse($zip->getFromName('database.sql'));
        $zip->setPassword('integration-test-password');
        $sql = $zip->getFromName('database.sql');
        self::assertStringNotContainsString('person@example.com', $sql);
        self::assertStringContainsString('@example.test', $sql);
        self::assertSame('<?php echo "ok";', $zip->getFromName('site/index.php'));
        $manifest = json_decode($zip->getFromName('mxbackup-manifest.json'), true);
        self::assertSame('applied', $manifest['masking']);
        self::assertSame(['modx_contacts'], $result->getReport()['stats']['table_names']);
        self::assertSame(1, $result->getReport()['stats']['masked_columns']);
        self::assertSame('mask', $result->getReport()['stats']['masking_tables']['modx_contacts']['columns']['email']);
        self::assertTrue($result->getReport()['stats']['encrypted']);
        self::assertSame('zip-aes-256', $result->getReport()['stats']['encryption_method']);
        self::assertStringNotContainsString('integration-test-password', json_encode($result->getReport()));
        self::assertSame(['info', 'info'], array_column($platform->logs, 'level'));
        $zip->close();

        foreach (glob($storage . '/*') ?: [] as $file) @unlink($file);
        @unlink($storage . '/.mxbackup.lock');
        @unlink($site . '/index.php'); @rmdir($storage); @rmdir($site); @rmdir($base);
    }

    /**
     * Убитый извне запуск (SIGKILL минует finally) оставляет рабочий каталог с
     * незамаскированным дампом и недоделанный архив. Следующий запуск обязан
     * подчистить их и сказать об этом в отчёте.
     */
    public function testRunRemovesArtifactsLeftByKilledRun()
    {
        $base = sys_get_temp_dir() . '/mxb-orphans-' . bin2hex(random_bytes(5));
        $site = $base . '/site';
        $storage = $base . '/backups';
        mkdir($site, 0700, true);
        mkdir($storage, 0700, true);
        file_put_contents($site . '/index.php', '<?php echo "ok";');

        $orphanWorkspace = $storage . '/.mxbackup-0123456789abcdef';
        mkdir($orphanWorkspace, 0700);
        file_put_contents($orphanWorkspace . '/database.sql', 'INSERT INTO users VALUES ("person@example.com")');
        $orphanArchive = $storage . '/.mxbackup-prod-20260101-000000.part.tar';
        file_put_contents($orphanArchive, 'partial');

        $platform = new FakePlatform($site, new FakeDatabase(['modx_contacts' => [['id' => 1, 'title' => 'Keep me']]]));
        $config = (new ConfigResolver())->resolve(Defaults::values(), [], [], [
            'storage_path' => $storage, 'format' => 'zip', 'profile' => 'prod',
        ], []);

        $result = (new BackupRunner($platform))->run($config, false);

        self::assertTrue($result->isSuccess());
        self::assertDirectoryDoesNotExist($orphanWorkspace);
        self::assertFileDoesNotExist($orphanArchive);
        $warnings = $result->getReport()['warnings'];
        self::assertCount(1, $warnings);
        self::assertStringContainsString('.mxbackup-0123456789abcdef', $warnings[0]);
        self::assertStringContainsString('.mxbackup-prod-20260101-000000.part.tar', $warnings[0]);
        self::assertContains('warning', array_column($platform->logs, 'level'));

        @unlink($result->getArchivePath());
        foreach (glob($storage . '/*') ?: [] as $file) @unlink($file);
        @unlink($storage . '/.mxbackup.lock');
        @unlink($site . '/index.php');
        @rmdir($storage); @rmdir($site); @rmdir($base);
    }

    public function testConfigurationErrorsAreLoggedBeforeRunHistoryStarts()
    {
        $database = new FakeDatabase([]);
        $platform = new FakePlatform('/missing-site-root', $database);
        $config = Defaults::values();
        $config['profile'] = $config['profiles']['prod'];
        $config['profile']['encryption'] = ['enabled' => true, 'password' => 'secret'];

        try {
            (new BackupRunner($platform))->run($config, false);
            self::fail('Invalid encrypted tar.gz must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('только для ZIP', $e->getMessage());
        }

        self::assertCount(1, $platform->logs);
        self::assertSame('error', $platform->logs[0]['level']);
        self::assertSame(0, $platform->logs[0]['context']['run_id']);
        self::assertSame([], $platform->runs->records);
    }
}
