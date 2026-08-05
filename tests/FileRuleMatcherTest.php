<?php

namespace MxBackup\Tests;

use MxBackup\Core\Files\FileRuleMatcher;
use PHPUnit\Framework\TestCase;

final class FileRuleMatcherTest extends TestCase
{
    public function testExcludeWinsAndDirectoryPrefixIsRecursive()
    {
        $matcher = new FileRuleMatcher(['*'], ['core/cache/', '*.log']);
        self::assertTrue($matcher->includes('assets/app.js'));
        self::assertFalse($matcher->includes('core/cache/resource/a.php'));
        self::assertFalse($matcher->includes('error.log'));
        self::assertTrue($matcher->excludesDirectory('core/cache'));
    }
}
