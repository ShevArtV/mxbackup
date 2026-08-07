<?php

namespace MxBackup\Tests\Fake;

use MxBackup\Core\Contract\RemoteStorageInterface;
use RuntimeException;

/**
 * Хранилище в памяти: держит имена, размеры и время, запоминает вызовы.
 * Умеет притворяться недоступным — без этого не проверить fail-safe прогона.
 */
final class FakeRemoteStorage implements RemoteStorageInterface
{
    /** @var array<string, array{size: int, modified: int, metadata: array<string, string>}> */
    public $objects = [];

    /** @var array<int, string> */
    public $deleted = [];

    /** @var bool */
    public $failUpload = false;

    /** @var array<int, string> Имена, удаление которых не проходит. */
    public $undeletable = [];

    /** @var int */
    public $now = 0;

    public function describe()
    {
        return 'fake://storage/';
    }

    public function upload($path, $name, array $metadata = [])
    {
        if ($this->failUpload) {
            throw new RuntimeException('хранилище недоступно');
        }

        $this->objects[$name] = [
            'size' => is_file($path) ? (int) filesize($path) : 0,
            'modified' => $this->now ?: time(),
            'metadata' => $metadata,
        ];

        return 'fake://storage/' . $name;
    }

    public function download($name, $target)
    {
        if (!isset($this->objects[$name])) {
            throw new RuntimeException('нет такого архива: ' . $name);
        }
        file_put_contents($target, 'restored:' . $name);

        return $target;
    }

    public function listArchives()
    {
        $archives = [];
        foreach ($this->objects as $name => $meta) {
            $archives[] = ['name' => $name, 'size' => $meta['size'], 'modified' => $meta['modified']];
        }

        usort($archives, static function (array $a, array $b) {
            return $b['modified'] <=> $a['modified'];
        });

        return $archives;
    }

    public function delete($name)
    {
        if (in_array($name, $this->undeletable, true)) {
            throw new RuntimeException('удаление запрещено: ' . $name);
        }

        unset($this->objects[$name]);
        $this->deleted[] = $name;
    }

    /**
     * Положить объект «задним числом» — для проверок ротации по возрасту.
     */
    public function seed($name, $modified, $size = 100)
    {
        $this->objects[$name] = ['size' => $size, 'modified' => $modified, 'metadata' => []];
    }
}
