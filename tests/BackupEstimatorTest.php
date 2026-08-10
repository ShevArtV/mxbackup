<?php

namespace MxBackup\Tests;

use MxBackup\Core\BackupEstimator;
use MxBackup\Core\Config\ConfigResolver;
use MxBackup\Core\Config\Defaults;
use MxBackup\Tests\Fake\FakeDatabase;
use MxBackup\Tests\Fake\FakePlatform;
use PHPUnit\Framework\TestCase;

final class BackupEstimatorTest extends TestCase
{
    public function testCountsSelectedFilesAndReportsStorageSpace()
    {
        $base = sys_get_temp_dir() . '/mxb-estimate-' . bin2hex(random_bytes(5));
        $site = $base . '/site'; $storage = $base . '/backups';
        mkdir($site, 0700, true); mkdir($storage, 0700, true);
        file_put_contents($site . '/index.php', '12345');
        file_put_contents($site . '/skip.log', 'not selected');
        $config = (new ConfigResolver())->resolve(Defaults::values(), [], [], [
            'storage_path' => $storage,
            'profile' => 'prod',
            'profiles' => ['prod' => ['files' => ['include' => ['*.php'], 'exclude' => []]]],
        ], []);

        $result = (new BackupEstimator(new FakePlatform($site, new FakeDatabase([]))))->estimate($config);
        self::assertSame(1, $result['files']);
        self::assertSame(5, $result['file_bytes']);
        self::assertIsInt($result['free_bytes']);
        self::assertFalse($result['large']);

        @unlink($site . '/index.php'); @unlink($site . '/skip.log');
        @rmdir($storage); @rmdir($site); @rmdir($base);
    }
}
