<?php

namespace MxBackup\Core\Archive;

use RuntimeException;

/** Однопроходный POSIX ustar writer без промежуточного несжатого TAR. */
final class TarGzStreamWriter
{
    private $stream;
    private $checkpoint;

    public function write($path, iterable $files, $sqlPath, $manifestPath, callable $checkpoint = null)
    {
        if (!function_exists('gzopen')) {
            throw new RuntimeException('Для tar.gz требуется расширение zlib.');
        }
        $this->checkpoint = $checkpoint;
        $this->stream = gzopen($path, 'wb6');
        if ($this->stream === false) {
            throw new RuntimeException('Не удалось создать tar.gz: ' . $path);
        }

        try {
            foreach ($files as $file) {
                $this->addFile($file['absolute'], 'site/' . $file['relative']);
            }
            $this->addFile($sqlPath, 'database.sql');
            $this->addFile($manifestPath, 'mxbackup-manifest.json');
            $this->writeAll(str_repeat("\0", 1024));
            if (!gzclose($this->stream)) {
                throw new RuntimeException('Не удалось завершить tar.gz: ' . $path);
            }
            $this->stream = null;
        } catch (\Throwable $e) {
            if (is_resource($this->stream)) {
                gzclose($this->stream);
            }
            $this->stream = null;
            @unlink($path);
            throw $e;
        }

        return $path;
    }

    private function addFile($source, $entry)
    {
        $this->check();
        $size = filesize($source);
        if ($size === false) {
            throw new RuntimeException('Не удалось определить размер файла: ' . $entry);
        }
        $mtime = filemtime($source);
        $mode = fileperms($source);
        $pax = [];
        [$name, $prefix] = $this->splitPath($entry);
        if ($name === null) {
            throw new RuntimeException('Путь слишком длинный для совместимого TAR: ' . $entry);
        }
        if ($size > 077777777777) {
            $pax['size'] = (string) $size;
        }
        if ($pax) {
            $body = $this->paxBody($pax);
            $paxName = 'PaxHeaders.mxbackup/' . $this->fallbackName($entry);
            $this->writeHeader($paxName, '', strlen($body), time(), 0644, 'x');
            $this->writeAll($body);
            $this->pad(strlen($body));
        }

        $this->writeHeader($name, $prefix, $pax ? min($size, 077777777777) : $size,
            $mtime === false ? time() : $mtime, $mode === false ? 0644 : ($mode & 0777), '0');
        $handle = fopen($source, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Не удалось открыть файл: ' . $entry);
        }
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Ошибка чтения файла: ' . $entry);
                }
                if ($chunk !== '') {
                    $this->writeAll($chunk);
                    $this->check();
                }
            }
        } finally {
            fclose($handle);
        }
        $this->pad($size);
    }

    private function writeHeader($name, $prefix, $size, $mtime, $mode, $type)
    {
        $header = str_pad(substr($name, 0, 100), 100, "\0")
            . $this->octal($mode, 8)
            . $this->octal(0, 8)
            . $this->octal(0, 8)
            . $this->octal($size, 12)
            . $this->octal($mtime, 12)
            . str_repeat(' ', 8)
            . $type
            . str_repeat("\0", 100)
            . "ustar\0"
            . "00"
            . str_pad('mxbackup', 32, "\0")
            . str_pad('mxbackup', 32, "\0")
            . $this->octal(0, 8)
            . $this->octal(0, 8)
            . str_pad(substr($prefix, 0, 155), 155, "\0")
            . str_repeat("\0", 12);
        $checksum = array_sum(unpack('C*', $header));
        $header = substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);
        $this->writeAll($header);
    }

    private function splitPath($path)
    {
        if (strlen($path) <= 100) {
            return [$path, ''];
        }
        $offset = strlen($path);
        while (($offset = strrpos(substr($path, 0, $offset), '/')) !== false) {
            $prefix = substr($path, 0, $offset);
            $name = substr($path, $offset + 1);
            if (strlen($prefix) <= 155 && strlen($name) <= 100) {
                return [$name, $prefix];
            }
        }
        return [null, null];
    }

    private function fallbackName($path)
    {
        $base = basename($path);
        return substr($base !== '' ? $base : 'file', -100);
    }

    private function paxBody(array $values)
    {
        $body = '';
        foreach ($values as $key => $value) {
            $record = $key . '=' . $value . "\n";
            $length = strlen($record) + 3;
            while (strlen((string) $length) + 1 + strlen($record) !== $length) {
                $length = strlen((string) $length) + 1 + strlen($record);
            }
            $body .= $length . ' ' . $record;
        }
        return $body;
    }

    private function octal($value, $length)
    {
        return str_pad(sprintf('%o', (int) $value), $length - 1, '0', STR_PAD_LEFT) . "\0";
    }

    private function pad($size)
    {
        $remainder = $size % 512;
        if ($remainder) {
            $this->writeAll(str_repeat("\0", 512 - $remainder));
        }
    }

    private function writeAll($data)
    {
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $written = gzwrite($this->stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Ошибка записи tar.gz.');
            }
            $offset += $written;
        }
    }

    private function check()
    {
        if ($this->checkpoint) {
            ($this->checkpoint)();
        }
    }
}
