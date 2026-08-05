<?php

namespace MxBackup\Tests;

use MxBackup\Core\Config\ConfigValidator;
use MxBackup\Core\Config\Defaults;
use PHPUnit\Framework\TestCase;

final class ConfigValidatorTest extends TestCase
{
    public function testEncryptedProfileRequiresZip()
    {
        $config = $this->config('tar.gz', true, 'secret');
        self::assertContains('шифрование доступно только для ZIP', (new ConfigValidator())->validate($config));
    }

    public function testEncryptedProfileRequiresPassword()
    {
        $config = $this->config('zip', true, '');
        self::assertContains('для шифрования требуется пароль', (new ConfigValidator())->validate($config));
    }

    public function testValidEncryptedZipPassesWhenRuntimeSupportsAes256()
    {
        if (!class_exists('ZipArchive')
            || !method_exists('ZipArchive', 'setEncryptionName')
            || !defined('ZipArchive::EM_AES_256')) {
            self::markTestSkipped('AES-256 ZIP encryption is unavailable');
        }
        self::assertSame([], (new ConfigValidator())->validate($this->config('zip', true, 'secret')));
    }

    private function config($format, $enabled, $password)
    {
        $defaults = Defaults::values();
        $profile = $defaults['profiles']['prod'];
        $profile['format'] = $format;
        $profile['encryption'] = ['enabled' => $enabled, 'password' => $password];
        $defaults['profile'] = $profile;
        return $defaults;
    }
}
