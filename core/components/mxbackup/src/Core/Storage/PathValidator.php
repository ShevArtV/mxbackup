<?php

namespace MxBackup\Core\Storage;

use RuntimeException;

final class PathValidator
{
    public function validateCandidate($storagePath, $siteRoot, $allowWebStorage = false)
    {
        $storagePath = $this->normalize($storagePath);
        $siteRoot = $this->normalize($siteRoot);
        if ($storagePath === '' || $storagePath[0] !== DIRECTORY_SEPARATOR) {
            throw new RuntimeException('Путь хранения должен быть абсолютным.');
        }
        $realSite = realpath($siteRoot);
        if ($realSite === false) throw new RuntimeException('Корень сайта не существует.');
        $existing = realpath($storagePath);
        if ($existing !== false) return $this->validate($existing, $realSite, $allowWebStorage);

        $parent = realpath(dirname($storagePath));
        if ($parent === false || !is_dir($parent) || !is_writable($parent)) {
            throw new RuntimeException('Родительский каталог хранения не существует или недоступен для записи: ' . dirname($storagePath));
        }
        $candidate = $parent . DIRECTORY_SEPARATOR . basename($storagePath);
        if ($candidate === $realSite) throw new RuntimeException('Нельзя сохранять архив прямо в корень сайта.');
        if (!$allowWebStorage && strpos($candidate . DIRECTORY_SEPARATOR, $realSite . DIRECTORY_SEPARATOR) === 0) {
            throw new RuntimeException('Каталог хранения внутри webroot запрещён без allow_web_storage.');
        }
        $cachePath = $realSite . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'cache';
        if ($candidate === $cachePath || strpos($candidate . DIRECTORY_SEPARATOR, $cachePath . DIRECTORY_SEPARATOR) === 0) {
            throw new RuntimeException('Нельзя сохранять архив в core/cache.');
        }
        return $candidate;
    }

    public function validate($storagePath, $siteRoot, $allowWebStorage = false)
    {
        $storagePath = $this->normalize($storagePath);
        $siteRoot = $this->normalize($siteRoot);
        if ($storagePath === '' || $storagePath[0] !== DIRECTORY_SEPARATOR) {
            throw new RuntimeException('Путь хранения должен быть абсолютным.');
        }
        if (!is_dir($storagePath)) {
            throw new RuntimeException('Каталог хранения не существует: ' . $storagePath);
        }
        if (!is_writable($storagePath)) {
            throw new RuntimeException('Каталог хранения недоступен для записи: ' . $storagePath);
        }

        $realStorage = realpath($storagePath);
        $realSite = realpath($siteRoot);
        if ($realStorage === false || $realSite === false) {
            throw new RuntimeException('Не удалось разрешить реальный путь хранения или сайта.');
        }
        if ($realStorage === $realSite) {
            throw new RuntimeException('Нельзя сохранять архив прямо в корень сайта.');
        }
        $cachePath = $realSite . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'cache';
        if ($realStorage === $cachePath || strpos($realStorage . DIRECTORY_SEPARATOR, $cachePath . DIRECTORY_SEPARATOR) === 0) {
            throw new RuntimeException('Нельзя сохранять архив в core/cache.');
        }
        if (!$allowWebStorage && strpos($realStorage . DIRECTORY_SEPARATOR, $realSite . DIRECTORY_SEPARATOR) === 0) {
            throw new RuntimeException('Каталог хранения внутри webroot запрещён без allow_web_storage.');
        }

        return $realStorage;
    }

    private function normalize($path)
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim((string)$path));
        return rtrim($path, DIRECTORY_SEPARATOR);
    }
}
