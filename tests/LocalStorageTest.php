<?php

namespace MxBackup\Tests;

use MxBackup\Core\Storage\LocalStorage;
use PHPUnit\Framework\TestCase;

final class LocalStorageTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mxbackup-storage-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0750, true);
    }

    protected function tearDown(): void
    {
        (new LocalStorage())->removeTree($this->root);
    }

    public function testPurgeOrphansRemovesWorkspacesAndPartialArchives()
    {
        $workspace = $this->root . '/.mxbackup-0123456789abcdef';
        mkdir($workspace, 0700);
        // Ради этого файла всё и затевается: незамаскированный дамп не должен
        // пережить убитый запуск.
        file_put_contents($workspace . '/database.sql', 'INSERT INTO users …');
        file_put_contents($this->root . '/.mxbackup-prod-20260806-010203.part.tar', 'partial');
        file_put_contents($this->root . '/.mxbackup-dev-20260806-010203.part.tar.gz', 'partial');

        $removed = (new LocalStorage())->purgeOrphans($this->root);

        sort($removed);
        self::assertSame([
            '.mxbackup-0123456789abcdef',
            '.mxbackup-dev-20260806-010203.part.tar.gz',
            '.mxbackup-prod-20260806-010203.part.tar',
        ], $removed);
        self::assertDirectoryDoesNotExist($workspace);
        self::assertFileDoesNotExist($this->root . '/.mxbackup-prod-20260806-010203.part.tar');
    }

    public function testPurgeOrphansKeepsArchivesLockAndForeignFiles()
    {
        $archive = $this->root . '/mxbackup-prod-20260806-010203.tar.gz';
        file_put_contents($archive, 'archive');
        file_put_contents($this->root . '/.mxbackup.lock', '{"pid":1}');
        file_put_contents($this->root . '/notes.txt', 'чужой файл рядом с архивами');
        mkdir($this->root . '/.mxbackup-not-a-workspace', 0700);

        $removed = (new LocalStorage())->purgeOrphans($this->root);

        self::assertSame([], $removed);
        self::assertFileExists($archive);
        self::assertFileExists($this->root . '/.mxbackup.lock');
        self::assertFileExists($this->root . '/notes.txt');
        self::assertDirectoryExists($this->root . '/.mxbackup-not-a-workspace');
    }

    /**
     * Restore распаковывает архив в рабочий каталог ДО захвата блокировки,
     * поэтому свой каталог он передаёт в $keep — иначе уборка снесла бы данные,
     * которые сама же операция и восстанавливает.
     */
    public function testPurgeOrphansKeepsExplicitlyProtectedWorkspace()
    {
        $mine = $this->root . '/.mxbackup-aaaaaaaaaaaaaaaa';
        $orphan = $this->root . '/.mxbackup-bbbbbbbbbbbbbbbb';
        mkdir($mine, 0700);
        mkdir($orphan, 0700);
        file_put_contents($mine . '/database.sql', 'restoring…');

        $removed = (new LocalStorage())->purgeOrphans($this->root, [$mine]);

        self::assertSame(['.mxbackup-bbbbbbbbbbbbbbbb'], $removed);
        self::assertFileExists($mine . '/database.sql');
        self::assertDirectoryDoesNotExist($orphan);
    }

    public function testPurgeOrphansOnMissingDirectoryIsHarmless()
    {
        self::assertSame([], (new LocalStorage())->purgeOrphans($this->root . '/nope'));
    }
}
