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
}
