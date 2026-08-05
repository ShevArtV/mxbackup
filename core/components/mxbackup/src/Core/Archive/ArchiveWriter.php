<?php

namespace MxBackup\Core\Archive;

use Phar;
use PharData;
use RuntimeException;
use ZipArchive;

final class ArchiveWriter
{
    public function write($path, $format, iterable $files, $sqlPath, $manifestPath, $password = null)
    {
        if ($format === 'zip') {
            return $this->writeZip($path, $files, $sqlPath, $manifestPath, $password);
        }
        if ($format === 'tar.gz') {
            if ($password !== null && $password !== '') {
                throw new RuntimeException('Шифрование доступно только для ZIP.');
            }
            return $this->writeTarGz($path, $files, $sqlPath, $manifestPath);
        }
        throw new RuntimeException('Неподдерживаемый формат архива: ' . $format);
    }

    private function writeZip($path, iterable $files, $sqlPath, $manifestPath, $password)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Для ZIP требуется расширение ext-zip.');
        }
        $encrypted = $password !== null && $password !== '';
        if ($encrypted && (!method_exists('ZipArchive', 'setEncryptionName') || !defined('ZipArchive::EM_AES_256'))) {
            throw new RuntimeException('Текущая сборка ext-zip не поддерживает AES-256.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось создать ZIP: ' . $path);
        }
        try {
            foreach ($files as $file) {
                $this->addZipFile($zip, $file['absolute'], 'site/' . $file['relative'], $password);
            }
            $this->addZipFile($zip, $sqlPath, 'database.sql', $password);
            $this->addZipFile($zip, $manifestPath, 'mxbackup-manifest.json', $password);
            if (!$zip->close()) {
                throw new RuntimeException('Не удалось завершить ZIP: ' . $path);
            }
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($path);
            throw $e;
        }
        if ($encrypted) {
            $this->verifyZipEncryption($path, $password);
        }
        return $path;
    }

    private function addZipFile(ZipArchive $zip, $source, $entry, $password)
    {
        if (!$zip->addFile($source, $entry)) {
            throw new RuntimeException('Не удалось добавить в ZIP: ' . $entry);
        }
        if ($password !== null && $password !== ''
            && !$zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $password)) {
            throw new RuntimeException('Не удалось зашифровать элемент ZIP: ' . $entry);
        }
    }

    private function verifyZipEncryption($path, $password)
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            @unlink($path);
            throw new RuntimeException('Не удалось проверить зашифрованный ZIP: ' . $path);
        }
        $withoutPassword = $zip->getFromName('mxbackup-manifest.json');
        $zip->setPassword($password);
        $withPassword = $zip->getFromName('mxbackup-manifest.json');
        $zip->close();
        if ($withoutPassword !== false || $withPassword === false) {
            @unlink($path);
            throw new RuntimeException('Проверка AES-256 шифрования ZIP не пройдена.');
        }
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
