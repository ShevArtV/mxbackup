<?php

namespace MxBackup\Core\Restore;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class FileRestorer
{
    public function restore($stagedSitePath, $siteRoot)
    {
        $source = realpath((string)$stagedSitePath);
        $targetRoot = realpath((string)$siteRoot);
        if ($source === false || !is_dir($source)) {
            throw new RuntimeException('В архиве отсутствует каталог site/.');
        }
        if ($targetRoot === false || !is_dir($targetRoot) || !is_writable($targetRoot)) {
            throw new RuntimeException('Корень сайта недоступен для восстановления файлов.');
        }

        $restored = 0;
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                throw new RuntimeException('Во временном каталоге найден недопустимый элемент.');
            }
            $relative = ltrim(substr($file->getPathname(), strlen($source)), DIRECTORY_SEPARATOR);
            $target = $targetRoot . DIRECTORY_SEPARATOR . $relative;
            $this->ensureSafeParents(dirname($target), $targetRoot);
            if (is_link($target) || (file_exists($target) && !is_file($target))) {
                throw new RuntimeException('Нельзя заменить не-файл или ссылку: ' . $relative);
            }
            $mode = is_file($target) ? (fileperms($target) & 0777) : (fileperms($file->getPathname()) & 0777);
            if (!$mode) $mode = 0644;
            $temporary = dirname($target) . DIRECTORY_SEPARATOR . '.mxbackup-restore-' . bin2hex(random_bytes(6));
            if (!copy($file->getPathname(), $temporary)) {
                throw new RuntimeException('Не удалось подготовить файл: ' . $relative);
            }
            @chmod($temporary, $mode);
            if (!rename($temporary, $target)) {
                @unlink($temporary);
                throw new RuntimeException('Не удалось восстановить файл: ' . $relative);
            }
            $restored++;
            $bytes += (int)$file->getSize();
        }
        return ['files' => $restored, 'bytes' => $bytes, 'mode' => 'merge'];
    }

    private function ensureSafeParents($path, $root)
    {
        $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
        $current = $root;
        if ($relative !== '') {
            foreach (explode(DIRECTORY_SEPARATOR, $relative) as $part) {
                $current .= DIRECTORY_SEPARATOR . $part;
                if (is_link($current)) {
                    throw new RuntimeException('Ссылка в целевом пути восстановления: ' . $current);
                }
                if (!file_exists($current) && !mkdir($current, 0755)) {
                    throw new RuntimeException('Не удалось создать каталог: ' . $current);
                }
                if (!is_dir($current)) {
                    throw new RuntimeException('Часть целевого пути не является каталогом: ' . $current);
                }
            }
        }
    }
}
