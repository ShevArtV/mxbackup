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
        ], []);
        $result = (new BackupRunner($platform))->run($config, false);
        self::assertTrue($result->isSuccess());
        self::assertFileExists($result->getArchivePath());

        $zip = new ZipArchive(); $zip->open($result->getArchivePath());
        $sql = $zip->getFromName('database.sql');
        self::assertStringNotContainsString('person@example.com', $sql);
        self::assertStringContainsString('@example.test', $sql);
        self::assertSame('<?php echo "ok";', $zip->getFromName('site/index.php'));
        $manifest = json_decode($zip->getFromName('mxbackup-manifest.json'), true);
        self::assertSame('applied', $manifest['masking']);
        self::assertSame(['modx_contacts'], $result->getReport()['stats']['table_names']);
        self::assertSame(1, $result->getReport()['stats']['masked_columns']);
        self::assertSame('mask', $result->getReport()['stats']['masking_tables']['modx_contacts']['columns']['email']);
        $zip->close();

        foreach (glob($storage . '/*') ?: [] as $file) @unlink($file);
        @unlink($storage . '/.mxbackup.lock');
        @unlink($site . '/index.php'); @rmdir($storage); @rmdir($site); @rmdir($base);
    }
}
