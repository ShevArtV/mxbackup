<?php

namespace MxBackup\Core\Archive;

use Phar;
use PharData;
use RuntimeException;
use ZipArchive;

final class ArchiveWriter
{
    public function write($path, $format, iterable $files, $sqlPath, $manifestPath)
    {
        if ($format === 'zip') {
            return $this->writeZip($path, $files, $sqlPath, $manifestPath);
        }
        if ($format === 'tar.gz') {
            return $this->writeTarGz($path, $files, $sqlPath, $manifestPath);
        }
        throw new RuntimeException('Неподдерживаемый формат архива: ' . $format);
    }

    private function writeZip($path, iterable $files, $sqlPath, $manifestPath)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Для ZIP требуется расширение ext-zip.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось создать ZIP: ' . $path);
        }
        foreach ($files as $file) {
            if (!$zip->addFile($file['absolute'], 'site/' . $file['relative'])) {
                $zip->close();
                throw new RuntimeException('Не удалось добавить в ZIP: ' . $file['relative']);
            }
        }
        $zip->addFile($sqlPath, 'database.sql');
        $zip->addFile($manifestPath, 'mxbackup-manifest.json');
        if (!$zip->close()) {
            throw new RuntimeException('Не удалось завершить ZIP: ' . $path);
        }
        return $path;
    }

    private function writeTarGz($path, iterable $files, $sqlPath, $manifestPath)
    {
        if (!class_exists('PharData')) {
            throw new RuntimeException('Для tar.gz требуется расширение Phar.');
        }
        $tarPath = preg_replace('/\.gz$/', '', $path);
        @unlink($tarPath);
        @unlink($path);
        try {
            $tar = new PharData($tarPath);
            foreach ($files as $file) {
                $tar->addFile($file['absolute'], 'site/' . $file['relative']);
            }
            $tar->addFile($sqlPath, 'database.sql');
            $tar->addFile($manifestPath, 'mxbackup-manifest.json');
            $tar->compress(Phar::GZ);
            unset($tar);
            @unlink($tarPath);
        } catch (\Exception $e) {
            @unlink($tarPath);
            @unlink($path);
            throw new RuntimeException('Не удалось создать tar.gz: ' . $e->getMessage(), 0, $e);
        }
        if (!is_file($path)) {
            throw new RuntimeException('Phar не создал tar.gz: ' . $path);
        }
        return $path;
    }
}
