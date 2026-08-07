<?php

namespace MxBackup\Core\Storage;

use MxBackup\Core\Contract\RemoteStorageInterface;

/**
 * Ротация архивов в удалённом хранилище.
 *
 * Правила те же, что у локальной (`RetentionPolicy`), и по той же причине:
 * свежие `count` копий не удаляются никогда, даже если просрочены по возрасту.
 * Иначе остановившийся cron через `days` оставил бы сайт вовсе без копий —
 * причём в облаке это заметить труднее, чем на диске.
 *
 * Отличие одно: удалять нечем, кроме самого хранилища, и каждая ошибка удаления
 * не должна ронять прогон — архив уже создан и выгружен, а не убранный старый
 * файл это счёт за хранение, а не потеря данных.
 */
final class RemoteRetentionPolicy
{
    /**
     * @return array{deleted: array<int, string>, errors: array<int, string>}
     */
    public function cleanup(RemoteStorageInterface $storage, $days, $count, $now = null)
    {
        $now = $now ?: time();
        $archives = $storage->listArchives();

        $deleted = [];
        $errors = [];
        $cutoff = $days > 0 ? $now - ((int) $days * 86400) : 0;
        $protected = $count > 0 ? (int) $count : 0;

        foreach ($archives as $index => $archive) {
            if ($index < $protected) {
                continue;
            }

            $tooMany = $count > 0;
            $tooOld = $cutoff > 0 && $archive['modified'] < $cutoff;
            if (!$tooMany && !$tooOld) {
                continue;
            }

            try {
                $storage->delete($archive['name']);
                $deleted[] = $archive['name'];
            } catch (\Throwable $e) {
                $errors[] = $archive['name'] . ': ' . $e->getMessage();
            }
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }
}
