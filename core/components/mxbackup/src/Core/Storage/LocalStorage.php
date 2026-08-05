<?php

namespace MxBackup\Core\Storage;

use RuntimeException;

final class LocalStorage
{
    public function ensureDirectory($path)
    {
        if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
            throw new RuntimeException('Не удалось создать каталог хранения: ' . $path);
        }
    }

    public function createWorkspace($storagePath)
    {
        $path = rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.mxbackup-' . bin2hex(random_bytes(8));
        if (!mkdir($path, 0700, false)) {
            throw new RuntimeException('Не удалось создать временный каталог: ' . $path);
        }
        return $path;
    }

    public function finalize($temporaryArchive, $finalArchive)
    {
        if (!is_file($temporaryArchive)) {
            throw new RuntimeException('Временный архив не создан: ' . $temporaryArchive);
        }
        if (!rename($temporaryArchive, $finalArchive)) {
            throw new RuntimeException('Не удалось опубликовать архив: ' . $finalArchive);
        }
        @chmod($finalArchive, 0600);
        return $finalArchive;
    }

    public function removeTree($path)
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
