<?php

namespace MxBackup\Tests;

use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase
{
    public function testCoreDoesNotDependOnModxClasses()
    {
        $root = dirname(__DIR__) . '/core/components/mxbackup/src/Core';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') continue;
            $source = file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('/\\\\?(?:modX|xPDO|modProcessor|modMail)\\b/', $source, $file->getPathname());
            self::assertStringNotContainsString('Platform\\Modx2', $source, $file->getPathname());
        }
    }

    public function testManagerSettingsUsePostSafeFieldNames()
    {
        $root = dirname(__DIR__);
        $panel = file_get_contents($root . '/assets/components/mxbackup/js/mgr/widgets/settings.panel.js');
        $processor = file_get_contents($root . '/core/components/mxbackup/processors/mgr/config/update.class.php');

        self::assertDoesNotMatchRegularExpression("/(?:name|hiddenName): 'mxbackup\\./", $panel);
        self::assertStringContainsString("'storage_path' => 'mxbackup.storage_path'", $processor);
        self::assertStringContainsString('array_key_exists($field, $properties)', $processor);
    }

    public function testOnlyRunHistoryHasAnXpdoModel()
    {
        $schema = file_get_contents(dirname(__DIR__) . '/core/components/mxbackup/model/schema/mxbackup.mysql.schema.xml');

        self::assertStringContainsString('class="mxBackupRun"', $schema);
        self::assertStringNotContainsString('class="mxBackupProfile"', $schema);
        self::assertStringNotContainsString('class="mxBackupRule"', $schema);
    }

    public function testManagerProfileRowsNeverExposeEncryptionPassword()
    {
        require_once dirname(__DIR__) . '/core/components/mxbackup/processors/mgr/profile/store.php';
        $row = \mxBackupProfileStoreHelper::row([
            'name' => 'prod',
            'mode' => 'prod',
            'active' => true,
            'format' => 'zip',
            'encryption' => ['enabled' => true, 'password' => 'must-not-leak'],
            'files' => ['include' => ['*'], 'exclude' => []],
            'masking' => ['standard' => false],
        ]);

        self::assertTrue($row['encryption_enabled']);
        self::assertTrue($row['encryption_password_set']);
        self::assertArrayNotHasKey('encryption_password', $row);
        self::assertStringNotContainsString('must-not-leak', json_encode($row));
    }
}
