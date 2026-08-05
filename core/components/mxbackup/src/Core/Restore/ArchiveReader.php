<?php

namespace MxBackup\Core\Restore;

use PharData;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class ArchiveReader
{
    public function inspect($archivePath, $password = null)
    {
        $archivePath = $this->validateArchivePath($archivePath);
        $format = $this->detectFormat($archivePath);
        $entries = $format === 'zip'
            ? $this->inspectZip($archivePath, $password)
            : $this->inspectTar($archivePath);

        if (!isset($entries['mxbackup-manifest.json']) || !isset($entries['database.sql'])) {
            throw new RuntimeException('Архив не содержит manifest или database.sql.');
        }
        $siteFiles = 0;
        $siteBytes = 0;
        foreach ($entries as $name => $entry) {
            if (strpos($name, 'site/') === 0 && empty($entry['directory'])) {
                $siteFiles++;
                $siteBytes += (int)$entry['size'];
            }
        }
        $manifest = json_decode($entries['mxbackup-manifest.json']['content'], true);
        if (!is_array($manifest) || (int)(isset($manifest['schema']) ? $manifest['schema'] : 0) !== 1
            || empty($manifest['mxbackup_version']) || empty($manifest['modx_version'])
            || empty($manifest['profile']) || empty($manifest['mode'])
            || !isset($manifest['status']) || $manifest['status'] !== 'payload_ready') {
            throw new RuntimeException('Некорректный mxbackup-manifest.json.');
        }
        if (!isset($manifest['payload_checksums']['database.sql'])
            || !preg_match('/^[a-f0-9]{64}$/i', (string)$manifest['payload_checksums']['database.sql'])) {
            throw new RuntimeException('Manifest не содержит корректный checksum database.sql.');
        }

        $checksum = hash_file('sha256', $archivePath);
        if ($checksum === false) {
            throw new RuntimeException('Не удалось вычислить checksum архива.');
        }

        return [
            'archive_path' => $archivePath,
            'archive_name' => basename($archivePath),
            'archive_checksum' => $checksum,
            'confirmation' => substr($checksum, 0, 12),
            'format' => $format,
            'encrypted' => !empty($entries['mxbackup-manifest.json']['encrypted']),
            'manifest' => $manifest,
            'entries' => count($entries),
            'site_files' => $siteFiles,
            'site_bytes' => $siteBytes,
            'database_bytes' => (int)$entries['database.sql']['size'],
        ];
    }

    public function extract($archivePath, $workspace, $password = null)
    {
        $info = $this->inspect($archivePath, $password);
        $workspace = rtrim((string)$workspace, DIRECTORY_SEPARATOR);
        if (!is_dir($workspace) || !is_writable($workspace)) {
            throw new RuntimeException('Временный каталог восстановления недоступен.');
        }
        $required = (int)$info['site_bytes'] + (int)$info['database_bytes'];
        $free = @disk_free_space($workspace);
        if ($free !== false && $required > (int)($free * 0.9)) {
            throw new RuntimeException('Недостаточно места для безопасной распаковки архива.');
        }

        if ($info['format'] === 'zip') {
            $this->extractZip($info['archive_path'], $workspace, $password);
        } else {
            $this->extractTar($info['archive_path'], $workspace);
        }
        $afterExtractionChecksum = hash_file('sha256', $info['archive_path']);
        if ($afterExtractionChecksum === false
            || !hash_equals($info['archive_checksum'], strtolower($afterExtractionChecksum))) {
            throw new RuntimeException('Архив изменился во время preflight; восстановление отменено.');
        }

        $databasePath = $workspace . DIRECTORY_SEPARATOR . 'database.sql';
        $manifestPath = $workspace . DIRECTORY_SEPARATOR . 'mxbackup-manifest.json';
        if (!is_file($databasePath) || !is_file($manifestPath)) {
            throw new RuntimeException('После распаковки отсутствует manifest или database.sql.');
        }
        $actual = hash_file('sha256', $databasePath);
        $expected = strtolower((string)$info['manifest']['payload_checksums']['database.sql']);
        if ($actual === false || !hash_equals($expected, strtolower($actual))) {
            throw new RuntimeException('Checksum database.sql не совпадает с manifest.');
        }

        $info['workspace'] = $workspace;
        $info['site_path'] = $workspace . DIRECTORY_SEPARATOR . 'site';
        $info['database_path'] = $databasePath;
        $info['manifest_path'] = $manifestPath;
        return $info;
    }

    private function inspectZip($archivePath, $password)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Для восстановления ZIP требуется ext-zip.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Не удалось открыть ZIP-архив.');
        }
        if ($password !== null && $password !== '') {
            $zip->setPassword((string)$password);
        }
        $entries = [];
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!$stat || !isset($stat['name'])) {
                    throw new RuntimeException('Не удалось прочитать элемент ZIP.');
                }
                $name = $this->validateEntryName($stat['name']);
                if (isset($entries[$name])) {
                    throw new RuntimeException('Повторяющийся путь в архиве: ' . $name);
                }
                $directory = substr($name, -1) === '/';
                if ($this->zipEntryIsLink($zip, $index)) {
                    throw new RuntimeException('Символические ссылки в архиве запрещены: ' . $name);
                }
                $entry = ['size' => isset($stat['size']) ? (int)$stat['size'] : 0, 'directory' => $directory];
                if ($name === 'mxbackup-manifest.json') {
                    $content = $zip->getFromIndex($index);
                    if ($content === false) {
                        throw new RuntimeException('Не удалось прочитать manifest: проверьте пароль архива.');
                    }
                    if (strlen($content) > 10485760) {
                        throw new RuntimeException('Manifest превышает допустимый размер.');
                    }
                    $entry['content'] = $content;
                    $entry['encrypted'] = $this->zipEntryIsEncrypted($zip, $index);
                }
                $entries[$name] = $entry;
            }
        } finally {
            $zip->close();
        }
        return $entries;
    }

    private function inspectTar($archivePath)
    {
        if (!class_exists('PharData')) {
            throw new RuntimeException('Для восстановления tar.gz требуется Phar.');
        }
        try {
            $tar = new PharData($archivePath);
            $iterator = new RecursiveIteratorIterator($tar, RecursiveIteratorIterator::SELF_FIRST);
            $entries = [];
            foreach ($iterator as $file) {
                $name = $this->validateEntryName($iterator->getSubPathName());
                if ($file->isLink()) {
                    throw new RuntimeException('Символические ссылки в архиве запрещены: ' . $name);
                }
                $entry = ['size' => $file->isFile() ? (int)$file->getSize() : 0, 'directory' => $file->isDir()];
                if ($name === 'site' && !$entry['directory']) {
                    throw new RuntimeException('Элемент site в архиве должен быть каталогом.');
                }
                if ($name === 'mxbackup-manifest.json') {
                    if ($entry['size'] > 10485760) {
                        throw new RuntimeException('Manifest превышает допустимый размер.');
                    }
                    $entry['content'] = $file->getContent();
                    $entry['encrypted'] = false;
                }
                $entries[$name] = $entry;
            }
            unset($tar);
            return $entries;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException('Не удалось открыть tar.gz: ' . $e->getMessage(), 0, $e);
        }
    }

    private function extractZip($archivePath, $workspace, $password)
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Не удалось открыть ZIP-архив.');
        }
        if ($password !== null && $password !== '') {
            $zip->setPassword((string)$password);
        }
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = $this->validateEntryName($stat['name']);
                $target = $this->targetPath($workspace, $name);
                if ($name === 'site' && !$file->isDir()) {
                    throw new RuntimeException('Элемент site в архиве должен быть каталогом.');
                }
                if (substr($name, -1) === '/') {
                    $this->ensureDirectory($target);
                    continue;
                }
                $this->ensureDirectory(dirname($target));
                $input = $zip->getStream($name);
                if (!$input) {
                    throw new RuntimeException('Не удалось распаковать элемент: ' . $name . '. Проверьте пароль.');
                }
                $output = fopen($target, 'xb');
                if (!$output) {
                    fclose($input);
                    throw new RuntimeException('Не удалось создать временный файл: ' . $name);
                }
                $copied = stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                if ($copied === false || $copied !== (int)$stat['size']) {
                    throw new RuntimeException('Элемент распакован не полностью: ' . $name);
                }
                @chmod($target, $this->zipEntryMode($zip, $index));
            }
        } finally {
            $zip->close();
        }
    }

    private function extractTar($archivePath, $workspace)
    {
        try {
            $tar = new PharData($archivePath);
            $iterator = new RecursiveIteratorIterator($tar, RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $file) {
                $name = $this->validateEntryName($iterator->getSubPathName());
                $target = $this->targetPath($workspace, $name);
                if ($file->isDir()) {
                    $this->ensureDirectory($target);
                    continue;
                }
                $this->ensureDirectory(dirname($target));
                if (!copy($file->getPathname(), $target)) {
                    throw new RuntimeException('Не удалось распаковать элемент: ' . $name);
                }
                @chmod($target, $file->getPerms() & 0777 ?: 0644);
            }
            unset($tar);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException('Не удалось распаковать tar.gz: ' . $e->getMessage(), 0, $e);
        }
    }

    private function validateArchivePath($archivePath)
    {
        $real = realpath((string)$archivePath);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new RuntimeException('Архив не существует или недоступен для чтения.');
        }
        return $real;
    }

    private function detectFormat($archivePath)
    {
        $lower = strtolower($archivePath);
        if (substr($lower, -4) === '.zip') return 'zip';
        if (substr($lower, -7) === '.tar.gz' || substr($lower, -4) === '.tgz') return 'tar.gz';
        throw new RuntimeException('Поддерживаются только ZIP и tar.gz архивы.');
    }

    private function validateEntryName($name)
    {
        $name = str_replace('\\', '/', (string)$name);
        if ($name === '' || strpos($name, "\0") !== false || $name[0] === '/' || preg_match('/^[a-z]:\//i', $name)) {
            throw new RuntimeException('Недопустимый путь в архиве.');
        }
        $parts = explode('/', rtrim($name, '/'));
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('Недопустимый путь в архиве: ' . $name);
            }
        }
        if ($name !== 'database.sql' && $name !== 'mxbackup-manifest.json' && $name !== 'site' && strpos($name, 'site/') !== 0) {
            throw new RuntimeException('Неизвестный элемент в архиве mxBackup: ' . $name);
        }
        return $name;
    }

    private function targetPath($workspace, $entry)
    {
        return $workspace . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rtrim($entry, '/'));
    }

    private function ensureDirectory($path)
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Не удалось создать временный каталог: ' . $path);
        }
    }

    private function zipEntryIsLink(ZipArchive $zip, $index)
    {
        $opsys = 0;
        $attributes = 0;
        return method_exists($zip, 'getExternalAttributesIndex')
            && $zip->getExternalAttributesIndex($index, $opsys, $attributes)
            && (($attributes >> 16) & 0170000) === 0120000;
    }

    private function zipEntryMode(ZipArchive $zip, $index)
    {
        $opsys = 0;
        $attributes = 0;
        if (method_exists($zip, 'getExternalAttributesIndex')
            && $zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            $mode = ($attributes >> 16) & 0777;
            if ($mode) return $mode;
        }
        return 0644;
    }

    private function zipEntryIsEncrypted(ZipArchive $zip, $index)
    {
        if (method_exists($zip, 'getEncryptionName')) {
            $method = $zip->getEncryptionName($zip->getNameIndex($index));
            return $method !== false && (!defined('ZipArchive::EM_NONE') || $method !== ZipArchive::EM_NONE);
        }
        $stat = $zip->statIndex($index, defined('ZipArchive::FL_ENC_RAW') ? ZipArchive::FL_ENC_RAW : 0);
        return isset($stat['encryption_method']) && (int)$stat['encryption_method'] !== 0;
    }
}
