<?php

namespace MxBackup\Tests;

use MxBackup\Core\Database\DatabaseDumper;
use MxBackup\Core\Masking\Masker;
use MxBackup\Core\Masking\StandardRules;
use MxBackup\Tests\Fake\FakeDatabase;
use PHPUnit\Framework\TestCase;

final class DatabaseDumperTest extends TestCase
{
    public function testDumpMasksPiiAndTruncatesSessions()
    {
        $database = new FakeDatabase([
            'modx_user_attributes' => [[
                'internalKey' => 7, 'fullname' => 'Иван', 'email' => 'ivan@example.com',
                'phone' => '+7999', 'mobilephone' => '', 'address' => 'Secret',
                'city' => 'Moscow', 'zip' => '123', 'comment' => 'private', 'extended' => '{}',
            ]],
            'modx_session' => [['id' => 'abc', 'data' => 'private']],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'mxb-sql-');
        $masker = new Masker(StandardRules::rules(), null, StandardRules::requiredColumns());
        (new DatabaseDumper($database, $masker))->dump($path, $database->listTables());
        $sql = file_get_contents($path);
        @unlink($path);
        self::assertStringNotContainsString('ivan@example.com', $sql);
        self::assertStringNotContainsString("'private'", $sql);
        self::assertStringContainsString('@example.test', $sql);
        self::assertStringNotContainsString('INSERT INTO `modx_session`', $sql);
    }
}
