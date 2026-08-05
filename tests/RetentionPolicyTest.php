<?php

namespace MxBackup\Tests;

use MxBackup\Core\Storage\RetentionPolicy;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase
{
    private $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mxbackup-retention-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testAgeNeverRemovesTheProtectedNewestCopies()
    {
        $now = 1785000000;
        $this->archive('mxbackup-prod-1.tar.gz', $now - 100 * 86400);
        $this->archive('mxbackup-prod-2.tar.gz', $now - 200 * 86400);
        $this->archive('mxbackup-prod-3.tar.gz', $now - 300 * 86400);

        $deleted = (new RetentionPolicy())->cleanup($this->directory, 30, 2, $now);

        self::assertSame(['mxbackup-prod-3.tar.gz'], $this->remainingNames($deleted));
        self::assertSame(
            ['mxbackup-prod-1.tar.gz', 'mxbackup-prod-2.tar.gz'],
            $this->files(),
            'Остановившийся cron не должен приводить к пустому каталогу архивов.'
        );
    }

    public function testCountStillRemovesEverythingBeyondTheLimit()
    {
        $now = 1785000000;
        $this->archive('mxbackup-prod-1.tar.gz', $now - 1 * 86400);
        $this->archive('mxbackup-prod-2.tar.gz', $now - 2 * 86400);
        $this->archive('mxbackup-prod-3.zip', $now - 3 * 86400);

        (new RetentionPolicy())->cleanup($this->directory, 0, 1, $now);

        self::assertSame(['mxbackup-prod-1.tar.gz'], $this->files());
    }

    public function testWithoutCountLimitAgeAloneCleansUp()
    {
        $now = 1785000000;
        $this->archive('mxbackup-prod-1.tar.gz', $now - 10 * 86400);
        $this->archive('mxbackup-prod-2.tar.gz', $now - 40 * 86400);

        (new RetentionPolicy())->cleanup($this->directory, 30, 0, $now);

        self::assertSame(['mxbackup-prod-1.tar.gz'], $this->files());
    }

    public function testForeignFilesAreNeverTouched()
    {
        $now = 1785000000;
        $this->archive('mxbackup-prod-1.tar.gz', $now - 400 * 86400);
        $this->archive('database-dump.sql', $now - 400 * 86400);

        (new RetentionPolicy())->cleanup($this->directory, 30, 0, $now);

        self::assertSame(['database-dump.sql'], $this->files());
    }

    private function archive($name, $mtime)
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, 'x');
        touch($path, $mtime);
    }

    private function files()
    {
        $names = array_map('basename', glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: []);
        sort($names, SORT_STRING);
        return $names;
    }

    private function remainingNames(array $deleted)
    {
        return array_map('basename', $deleted);
    }
}
