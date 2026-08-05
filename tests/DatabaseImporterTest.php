<?php

namespace MxBackup\Tests;

use MxBackup\Core\Restore\DatabaseImporter;
use MxBackup\Tests\Fake\FakeDatabase;
use PHPUnit\Framework\TestCase;

final class DatabaseImporterTest extends TestCase
{
    public function testStreamsCompleteStatementsAndKeepsSemicolonsInsideStrings()
    {
        $path = tempnam(sys_get_temp_dir(), 'mxb-sql-');
        file_put_contents($path, "-- comment; ignored\nSET NAMES utf8mb4;\nCREATE TABLE `demo` (`value` varchar(50) DEFAULT 'a;b');\nINSERT INTO `demo` (`value`) VALUES ('x\\';y');\n");
        $database = new FakeDatabase([]);
        $report = (new DatabaseImporter($database))->import($path);
        self::assertSame(3, $report['statements']);
        self::assertCount(3, $database->restoredStatements);
        self::assertStringContainsString("DEFAULT 'a;b'", $database->restoredStatements[1]);
        self::assertStringContainsString("'x\\';y'", $database->restoredStatements[2]);
        @unlink($path);
    }

    public function testRejectsIncompleteStatement()
    {
        $path = tempnam(sys_get_temp_dir(), 'mxb-sql-');
        file_put_contents($path, 'CREATE TABLE `broken` (`id` int)');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('обрывается');
        try {
            (new DatabaseImporter(new FakeDatabase([])))->import($path);
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsStatementsOutsideMxBackupDumpGrammar()
    {
        $path = tempnam(sys_get_temp_dir(), 'mxb-sql-');
        file_put_contents($path, 'DELETE FROM `users`;');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('не создаёт');
        try {
            (new DatabaseImporter(new FakeDatabase([])))->import($path);
        } finally {
            @unlink($path);
        }
    }
}
