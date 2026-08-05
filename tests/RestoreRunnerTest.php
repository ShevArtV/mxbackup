<?php

namespace MxBackup\Tests;

use MxBackup\Core\Archive\ArchiveWriter;
use MxBackup\Core\Config\ConfigResolver;
use MxBackup\Core\Config\Defaults;
use MxBackup\Core\RestoreRunner;
use MxBackup\Tests\Fake\FakeDatabase;
use MxBackup\Tests\Fake\FakePlatform;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class RestoreRunnerTest extends TestCase
{
    public function testRestoresFilesAndDatabaseAfterSafetyBackup()
    {
        if (!class_exists('ZipArchive')) self::markTestSkipped('ext-zip missing');
        $base = sys_get_temp_dir() . '/mxb-restore-' . bin2hex(random_bytes(5));
        $site = $base . '/site';
        $storage = $base . '/backups';
        $payload = $base . '/payload';
        mkdir($site, 0700, true); mkdir($storage, 0700); mkdir($payload, 0700);
        file_put_contents($site . '/current.txt', 'current');
        file_put_contents($payload . '/index.php', 'restored');
        $sql = "SET NAMES utf8mb4;\nCREATE TABLE `restored` (`id` int);\nINSERT INTO `restored` (`id`) VALUES (7);\nSET FOREIGN_KEY_CHECKS=1;\n";
        file_put_contents($payload . '/database.sql', $sql);
        file_put_contents($payload . '/manifest.json', json_encode([
            'schema' => 1, 'mxbackup_version' => '1.1.0-beta', 'modx_version' => '2.8.8-pl',
            'profile' => 'prod', 'mode' => 'prod', 'status' => 'payload_ready',
            'payload_checksums' => ['database.sql' => hash('sha256', $sql)],
        ]));
        $archive = $storage . '/source.zip';
        $password = method_exists('ZipArchive', 'setEncryptionName') && defined('ZipArchive::EM_AES_256')
            ? 'restore-runner-secret'
            : null;
        (new ArchiveWriter())->write($archive, 'zip', [[
            'absolute' => $payload . '/index.php', 'relative' => 'index.php', 'size' => 8,
        ]], $payload . '/database.sql', $payload . '/manifest.json', $password);

        $database = new FakeDatabase([]);
        $platform = new FakePlatform($site, $database);
        $config = (new ConfigResolver())->resolve(Defaults::values(), [], [], [
            'storage_path' => $storage, 'format' => 'zip', 'profile' => 'prod',
        ], []);
        $runner = new RestoreRunner($platform);
        $preflight = $runner->preflight($archive, $password);
        try {
            $runner->restore($config, $archive, 'all', 'wrong-token', $password);
            self::fail('Wrong confirmation must prevent restore');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('код подтверждения', $e->getMessage());
            self::assertSame([], glob($storage . '/mxbackup-pre-restore-*') ?: []);
        }
        $result = $runner->restore($config, $archive, 'all', $preflight['confirmation'], $password);

        self::assertTrue($result->isSuccess());
        self::assertSame('restored', file_get_contents($site . '/index.php'));
        self::assertSame('current', file_get_contents($site . '/current.txt'));
        self::assertCount(4, $database->restoredStatements);
        $safety = $result->getReport()['stats']['safety_backup'];
        self::assertFileExists($safety);
        self::assertStringContainsString('mxbackup-pre-restore-', basename($safety));
        self::assertSame('restore', $result->getReport()['stats']['operation']);
        self::assertStringNotContainsString('restore-runner-secret', json_encode($result->getReport()));
        self::assertSame('restore', $platform->runs->records[1]['report_json']['stats']['operation']);

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($base);
    }
}
