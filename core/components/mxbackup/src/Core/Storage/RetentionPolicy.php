<?php

namespace MxBackup\Core\Storage;

final class RetentionPolicy
{
    public function cleanup($storagePath, $days, $count, $now = null)
    {
        $now = $now ?: time();
        $files = [];
        foreach (glob(rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mxbackup-*.*') ?: [] as $file) {
            if (is_file($file) && preg_match('/\.(?:zip|tar\.gz)$/', $file)) {
                $files[] = ['path' => $file, 'mtime' => filemtime($file) ?: 0];
            }
        }
        usort($files, static function ($a, $b) {
            return $b['mtime'] <=> $a['mtime'];
        });

        $deleted = [];
        $cutoff = $days > 0 ? $now - ((int)$days * 86400) : 0;
        // Свежие $count копий не удаляются никогда, в том числе по возрасту:
        // иначе остановившийся cron через retention_days оставлял бы сайт вовсе
        // без резервных копий. При $count = 0 ограничение снято сознательно, и
        // защищать нечего.
        $protected = $count > 0 ? (int)$count : 0;
        foreach ($files as $index => $file) {
            if ($index < $protected) {
                continue;
            }
            $tooMany = $count > 0;
            $tooOld = $cutoff > 0 && $file['mtime'] < $cutoff;
            if (($tooMany || $tooOld) && @unlink($file['path'])) {
                $deleted[] = $file['path'];
            }
        }
        return $deleted;
    }
}
