<?php

namespace MxBackup\Core\Archive;

use RuntimeException;
use ZipArchive;

final class ArchiveWriter
{
    public function write($path, $format, iterable $files, $sqlPath, $manifestPath, $password = null, callable $checkpoint = null)
    {
        if ($format === 'zip') {
            return $this->writeZip($path, $files, $sqlPath, $manifestPath, $password, $checkpoint);
        }
        if ($format === 'tar.gz') {
            if ($password !== null && $password !== '') {
                throw new RuntimeException('Шифрование доступно только для ZIP.');
            }
            return (new TarGzStreamWriter())->write($path, $files, $sqlPath, $manifestPath, $checkpoint);
        }
        throw new RuntimeException('Неподдерживаемый формат архива: ' . $format);
    }

    private function writeZip($path, iterable $files, $sqlPath, $manifestPath, $password, callable $checkpoint = null)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Для ZIP требуется расширение ext-zip.');
        }
        $encrypted = $password !== null && $password !== '';
        if ($encrypted && (!method_exists('ZipArchive', 'setEncryptionName') || !defined('ZipArchive::EM_AES_256'))) {
            throw new RuntimeException('Текущая сборка ext-zip не поддерживает AES-256.');
        }
        $zip = new ZipArchive();
        $closed = false;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось создать ZIP: ' . $path);
        }
        try {
            foreach ($files as $file) {
                if ($checkpoint) $checkpoint();
                $this->addZipFile($zip, $file['absolute'], 'site/' . $file['relative'], $password);
            }
            if ($checkpoint) $checkpoint();
            $this->addZipFile($zip, $sqlPath, 'database.sql', $password);
            $this->addZipFile($zip, $manifestPath, 'mxbackup-manifest.json', $password);
            if (!$zip->close()) {
                throw new RuntimeException('Не удалось завершить ZIP: ' . $path);
            }
            $closed = true;
            if ($checkpoint) $checkpoint();
        } catch (\Throwable $e) {
            if (!$closed) $zip->close();
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
        if (method_exists($zip, 'setExternalAttributesName')) {
            $mode = @fileperms($source);
            $mode = $mode === false ? 0644 : ($mode & 0777);
            $zip->setExternalAttributesName($entry, defined('ZipArchive::OPSYS_UNIX') ? ZipArchive::OPSYS_UNIX : 3, (0100000 | $mode) << 16);
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

}
