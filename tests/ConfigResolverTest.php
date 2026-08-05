<?php

namespace MxBackup\Tests;

use MxBackup\Core\Config\ConfigResolver;
use MxBackup\Core\Config\Defaults;
use PHPUnit\Framework\TestCase;

final class ConfigResolverTest extends TestCase
{
    public function testPrecedenceIsCliFileProfileSystemDefault()
    {
        $resolver = new ConfigResolver();
        $config = $resolver->resolve(
            Defaults::values(),
            ['format' => 'zip', 'storage_path' => '/system'],
            ['prod' => ['format' => 'tar.gz', 'storage_path' => '/profile']],
            ['format' => 'zip', 'storage_path' => '/file'],
            ['profile' => 'prod', 'format' => 'tar.gz', 'storage_path' => '/cli']
        );
        self::assertSame('tar.gz', $config['profile']['format']);
        self::assertSame('/cli', $config['profile']['storage_path']);
    }

    public function testStoredProfileBeatsSystemSetting()
    {
        $config = (new ConfigResolver())->resolve(
            Defaults::values(),
            ['format' => 'zip'],
            ['prod' => ['format' => 'tar.gz']],
            [],
            ['profile' => 'prod']
        );
        self::assertSame('tar.gz', $config['profile']['format']);
    }

    public function testSystemSettingBeatsBuiltInDefault()
    {
        $config = (new ConfigResolver())->resolve(Defaults::values(), ['format' => 'zip'], [], [], ['profile' => 'prod']);
        self::assertSame('zip', $config['profile']['format']);
    }
}
