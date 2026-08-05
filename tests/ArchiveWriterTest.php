<?php

namespace MxBackup\Tests;

use MxBackup\Core\Archive\ArchiveWriter;
use PHPUnit\Framework\TestCase;
use ZipArchive;
use PharData;

final class ArchiveWriterTest extends TestCase
{
    public function testWritesZipWithSiteDatabaseAndManifest()
    {
        if (!class_exists('ZipArchive')) self::markTestSkipped('ext-zip missing');
        $dir = sys_get_temp_dir() . '/mxb-zip-' . bin2hex(random_bytes(5));
        mkdir($dir);
        file_put_contents($dir . '/file.txt', 'payload');
        file_put_contents($dir . '/database.sql', 'SELECT 1;');
        file_put_contents($dir . '/manifest.json', '{}');
        $archive = $dir . '/backup.zip';
        (new ArchiveWriter())->write($archive, 'zip', [[
            'absolute' => $dir . '/file.txt', 'relative' => 'assets/file.txt', 'size' => 7,
        ]], $dir . '/database.sql', $dir . '/manifest.json');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        self::assertSame('payload', $zip->getFromName('site/assets/file.txt'));
        self::assertSame('SELECT 1;', $zip->getFromName('database.sql'));
        self::assertSame('{}', $zip->getFromName('mxbackup-manifest.json'));
        $zip->close();
        foreach (glob($dir . '/*') as $file) @unlink($file);
        @rmdir($dir);
    }

    public function testWritesTarGzWithSiteDatabaseAndManifest()
    {
        if (!class_exists('PharData')) self::markTestSkipped('PharData missing');
        $dir = sys_get_temp_dir() . '/mxb-tar-' . bin2hex(random_bytes(5));
        mkdir($dir);
        file_put_contents($dir . '/file.txt', 'payload');
        file_put_contents($dir . '/database.sql', 'SELECT 1;');
        file_put_contents($dir . '/manifest.json', '{}');
        $archive = $dir . '/backup.tar.gz';
        (new ArchiveWriter())->write($archive, 'tar.gz', [[
            'absolute' => $dir . '/file.txt', 'relative' => 'assets/file.txt', 'size' => 7,
        ]], $dir . '/database.sql', $dir . '/manifest.json');
        $tar = new PharData($archive);
        self::assertTrue(isset($tar['site/assets/file.txt']));
        self::assertSame(7, $tar['site/assets/file.txt']->getSize());
        self::assertSame(9, $tar['database.sql']->getSize());
        self::assertSame(2, $tar['mxbackup-manifest.json']->getSize());
        unset($tar);
        foreach (glob($dir . '/*') as $file) @unlink($file);
        @rmdir($dir);
    }
}
