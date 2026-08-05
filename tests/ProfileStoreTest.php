<?php

namespace MxBackup\Tests;

use MxBackup\Core\Config\ProfileStore;
use PHPUnit\Framework\TestCase;

final class ProfileStoreTest extends TestCase
{
    private $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mxbackup-profile-store-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) return;
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testProfilesAreStoredAsExecutablePhpConfigurationFiles()
    {
        $store = new ProfileStore($this->directory);
        $saved = $store->save($this->profile('dev'));

        self::assertSame('dev', $saved['name']);
        self::assertFileExists($this->directory . '/dev.php');
        self::assertSame('dev', $store->find('dev')['mode']);
        self::assertSame(['dev'], array_keys($store->all(true)));
        self::assertStringStartsWith("<?php\n\nreturn ", file_get_contents($this->directory . '/dev.php'));
        self::assertSame([], glob($this->directory . '/.mxbackup-*') ?: []);
    }

    public function testRulesAreCreatedUpdatedAndRemovedInsideProfileFile()
    {
        $store = new ProfileStore($this->directory);
        $store->save($this->profile('dev'));
        $created = $store->addRule('dev', [
            'target_type' => 'column', 'target' => 'modx_users.email',
            'action' => 'mask', 'value' => null, 'priority' => 100, 'active' => true,
        ]);

        self::assertSame(1, $created['id']);
        self::assertSame('mask', $store->findRule('dev', 1)['action']);
        $updated = $store->updateRule('dev', 1, ['action' => 'hide']);
        self::assertSame('hide', $updated['action']);
        self::assertTrue($store->removeRule('dev', 1));
        self::assertNull($store->findRule('dev', 1));
    }

    public function testProfileCanBeRenamedWithoutLeavingOldFile()
    {
        $store = new ProfileStore($this->directory);
        $profile = $store->save($this->profile('custom'));
        $profile['name'] = 'staging';
        $store->save($profile, 'custom');

        self::assertNull($store->find('custom'));
        self::assertSame('staging', $store->find('staging')['name']);
    }

    private function profile($name)
    {
        return [
            'name' => $name,
            'description' => 'Test profile',
            'mode' => $name === 'dev' ? 'dev' : 'custom',
            'active' => true,
            'format' => 'tar.gz',
            'files' => ['include' => ['*'], 'exclude' => []],
            'database' => ['include_tables' => ['*'], 'exclude_tables' => []],
            'masking' => ['standard' => $name === 'dev', 'rules' => []],
        ];
    }
}
