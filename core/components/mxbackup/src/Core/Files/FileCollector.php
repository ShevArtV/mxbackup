<?php

namespace MxBackup\Core\Files;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class FileCollector
{
    private $warnings = [];
    private $excluded = 0;

    public function collect($siteRoot, FileRuleMatcher $matcher)
    {
        $siteRoot = rtrim((string)realpath($siteRoot), DIRECTORY_SEPARATOR);
        $directory = new RecursiveDirectoryIterator($siteRoot, FilesystemIterator::SKIP_DOTS);
        $self = $this;
        $filter = new RecursiveCallbackFilterIterator($directory, static function ($current) use ($siteRoot, $matcher, $self) {
            $relative = ltrim(substr($current->getPathname(), strlen($siteRoot)), DIRECTORY_SEPARATOR);
            if ($current->isLink()) {
                $self->warnings[] = 'Пропущена символическая ссылка: ' . str_replace('\\', '/', $relative);
                $self->excluded++;
                return false;
            }
            if ($current->isDir() && $matcher->excludesDirectory($relative)) {
                $self->excluded++;
                return false;
            }
            return true;
        });

        $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = ltrim(substr($file->getPathname(), strlen($siteRoot)), DIRECTORY_SEPARATOR);
            $relative = str_replace('\\', '/', $relative);
            if (!$matcher->includes($relative, false)) {
                $this->excluded++;
                continue;
            }
            if (!$file->isReadable()) {
                $this->warnings[] = 'Файл недоступен для чтения: ' . $relative;
                continue;
            }
            yield [
                'absolute' => $file->getPathname(),
                'relative' => $relative,
                'size' => $file->getSize(),
            ];
        }
    }

    public function getWarnings()
    {
        return $this->warnings;
    }

    public function getExcludedCount()
    {
        return $this->excluded;
    }
}
