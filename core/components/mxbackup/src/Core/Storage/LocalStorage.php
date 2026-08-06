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

    /**
     * Удаляет осиротевшие временные артефакты прошлых запусков: недоделанные
     * архивы `.<имя>.part.<ext>` и рабочие каталоги `.mxbackup-<hex>`.
     *
     * Штатно их убирает блок finally, но при SIGKILL (обрыв SSH, лимит хостера,
     * OOM) он не выполняется. Мусор — полбеды: в рабочем каталоге лежит
     * незамаскированный `database.sql`, и он не должен переживать упавший запуск.
     *
     * Вызывать ТОЛЬКО при захваченном RunLock: блокировка общая для backup и
     * restore, поэтому под ней чужих живых артефактов быть не может. Свой
     * собственный рабочий каталог, если он создан раньше блокировки (так делает
     * restore — он распаковывает архив до неё), передаётся в $keep.
     *
     * @param string[] $keep Пути или имена артефактов, которые трогать нельзя.
     * @return string[] Имена удалённых артефактов.
     */
    public function purgeOrphans($storagePath, array $keep = [])
    {
        $protected = [];
        foreach ($keep as $path) {
            if ((string) $path !== '') {
                $protected[basename((string) $path)] = true;
            }
        }

        $storagePath = rtrim($storagePath, DIRECTORY_SEPARATOR);
        if (!is_dir($storagePath)) {
            return [];
        }

        $entries = scandir($storagePath);
        if ($entries === false) {
            return [];
        }

        $removed = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || isset($protected[$entry])) {
                continue;
            }
            $path = $storagePath . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && preg_match('/^\.mxbackup-[0-9a-f]{16}$/', $entry)) {
                $this->removeTree($path);
                $removed[] = $entry;
            } elseif (is_file($path) && preg_match('/^\..+\.part\.[a-z0-9.]+$/i', $entry)) {
                @unlink($path);
                $removed[] = $entry;
            }
        }

        return $removed;
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
