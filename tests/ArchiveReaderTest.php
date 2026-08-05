<?php

namespace MxBackup\Tests;

use MxBackup\Core\Archive\ArchiveWriter;
use MxBackup\Core\Restore\ArchiveReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ArchiveReaderTest extends TestCase
{
    public function testInspectsAndExtractsEncryptedZip()
    {
        if (!class_exists('ZipArchive') || !method_exists('ZipArchive', 'setEncryptionName') || !defined('ZipArchive::EM_AES_256')) {
            self::markTestSkipped('AES-256 ZIP encryption is unavailable');
        }
        $dir = $this->temporaryDirectory('reader-aes');
        $archive = $this->createArchive($dir, 'zip', 'restore-secret');
        $reader = new ArchiveReader();
        $info = $reader->inspect($archive, 'restore-secret');
        self::assertSame('zip', $info['format']);
        self::assertTrue($info['encrypted']);
        self::assertSame(1, $info['site_files']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $info['confirmation']);

        $workspace = $dir . '/extract';
        mkdir($workspace, 0700);
        $extracted = $reader->extract($archive, $workspace, 'restore-secret');
        self::assertSame('restored file', file_get_contents($extracted['site_path'] . '/index.php'));
        self::assertStringContainsString('CREATE TABLE', file_get_contents($extracted['database_path']));
        self::assertStringNotContainsString('restore-secret', json_encode($info));
        $this->removeDirectory($dir);
    }

    public function testRejectsWrongPasswordAndTraversalEntry()
    {
        if (!class_exists('ZipArchive')) self::markTestSkipped('ext-zip missing');
        $dir = $this->temporaryDirectory('reader-bad');
        if (method_exists('ZipArchive', 'setEncryptionName') && defined('ZipArchive::EM_AES_256')) {
            $archive = $this->createArchive($dir, 'zip', 'correct-password');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('пароль');
            try {
                (new ArchiveReader())->inspect($archive, 'wrong-password');
            } finally {
                $this->removeDirectory($dir);
            }
            return;
        }

        $archive = $this->createArchive($dir, 'zip');
        $zip = new ZipArchive();
        $zip->open($archive);
        $zip->addFromString('../outside.php', 'bad');
        $zip->close();
        $this->expectException(\RuntimeException::class);
        try {
            (new ArchiveReader())->inspect($archive);
        } finally {
            $this->removeDirectory($dir);
        }
    }

    public function testInspectsAndExtractsTarGz()
    {
        if (!class_exists('PharData')) self::markTestSkipped('PharData missing');
        $dir = $this->temporaryDirectory('reader-tar');
        $archive = $this->createArchive($dir, 'tar.gz');
        // Phar keeps a just-created compressed archive in-process with stale entry streams.
        // Reading a different path matches the real restore flow (archive from an earlier run).
        copy($archive, $dir . '/restore-source.tar.gz');
        $archive = $dir . '/restore-source.tar.gz';
        $workspace = $dir . '/extract';
        mkdir($workspace, 0700);
        $info = (new ArchiveReader())->extract($archive, $workspace);
        self::assertSame('tar.gz', $info['format']);
        self::assertSame('restored file', file_get_contents($workspace . '/site/index.php'));
        $this->removeDirectory($dir);
    }

    public function testRejectsPathTraversalEntry()
    {
        if (!class_exists('ZipArchive')) self::markTestSkipped('ext-zip missing');
        $dir = $this->temporaryDirectory('reader-traversal');
        $archive = $this->createArchive($dir, 'zip');
        $zip = new ZipArchive();
        $zip->open($archive);
        $zip->addFromString('../outside.php', 'bad');
        $zip->close();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Недопустимый путь');
        try {
            (new ArchiveReader())->inspect($archive);
        } finally {
            $this->removeDirectory($dir);
        }
    }

    private function createArchive($dir, $format, $password = null)
    {
        file_put_contents($dir . '/index.php', 'restored file');
        $sql = "SET NAMES utf8mb4;\nCREATE TABLE `demo` (`id` int);\nINSERT INTO `demo` (`id`) VALUES (1);\n";
        file_put_contents($dir . '/database.sql', $sql);
        $manifest = [
            'schema' => 1, 'mxbackup_version' => '1.1.0-beta', 'modx_version' => '2.8.8-pl',
            'profile' => 'prod', 'mode' => 'prod', 'status' => 'payload_ready',
            'payload_checksums' => ['database.sql' => hash('sha256', $sql)],
        ];
        file_put_contents($dir . '/manifest.json', json_encode($manifest));
        $archive = $dir . '/backup.' . $format;
        (new ArchiveWriter())->write($archive, $format, [[
            'absolute' => $dir . '/index.php', 'relative' => 'index.php', 'size' => 13,
        ]], $dir . '/database.sql', $dir . '/manifest.json', $password);
        return $archive;
    }

    private function temporaryDirectory($name)
    {
        $dir = sys_get_temp_dir() . '/mxb-' . $name . '-' . bin2hex(random_bytes(5));
        mkdir($dir, 0700, true);
        return $dir;
    }

    private function removeDirectory($path)
    {
        if (!is_dir($path)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($path);
    }
}
