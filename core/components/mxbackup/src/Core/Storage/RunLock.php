<?php

namespace MxBackup\Core\Storage;

use RuntimeException;

final class RunLock
{
    private $handle;
    private $path;

    public function acquire($storagePath, $ttlMinutes)
    {
        $this->path = rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.mxbackup.lock';
        $this->handle = fopen($this->path, 'c+');
        if (!$this->handle) {
            throw new RuntimeException('Не удалось открыть lock-файл.');
        }
        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            $metadata = stream_get_contents($this->handle);
            throw new RuntimeException('Другой backup уже выполняется.' . ($metadata ? ' ' . trim($metadata) : ''));
        }
        ftruncate($this->handle, 0);
        fwrite($this->handle, json_encode([
            'pid' => getmypid(),
            'started_at' => time(),
            'ttl_minutes' => (int)$ttlMinutes,
        ], JSON_UNESCAPED_SLASHES));
        fflush($this->handle);
    }

    public function release()
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
        // Lock-файл намеренно остаётся на месте. Удаление после unlock создаёт
        // race: следующий процесс уже мог открыть старый inode, а третий —
        // создать новый файл и получить второй независимый lock.
    }

    public function __destruct()
    {
        $this->release();
    }
}
