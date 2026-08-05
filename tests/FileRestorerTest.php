<?php

namespace MxBackup\Tests;

use MxBackup\Core\Restore\FileRestorer;
use PHPUnit\Framework\TestCase;

final class FileRestorerTest extends TestCase
{
    public function testRestoresInMergeModeAndKeepsExtraFiles()
    {
        $base = sys_get_temp_dir() . '/mxb-files-' . bin2hex(random_bytes(5));
        $source = $base . '/stage/site';
        $target = $base . '/target';
        mkdir($source . '/assets', 0700, true);
        mkdir($target . '/assets', 0700, true);
        file_put_contents($source . '/index.php', 'new index');
        file_put_contents($source . '/assets/app.js', 'new js');
        file_put_contents($target . '/index.php', 'old index');
        file_put_contents($target . '/extra.txt', 'keep');

        $report = (new FileRestorer())->restore($source, $target);
        self::assertSame(2, $report['files']);
        self::assertSame('new index', file_get_contents($target . '/index.php'));
        self::assertSame('new js', file_get_contents($target . '/assets/app.js'));
        self::assertSame('keep', file_get_contents($target . '/extra.txt'));

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($base);
    }
}
