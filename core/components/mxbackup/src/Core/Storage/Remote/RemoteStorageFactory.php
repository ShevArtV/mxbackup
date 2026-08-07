<?php

namespace MxBackup\Core\Storage\Remote;

use InvalidArgumentException;

/**
 * Сборка удалённого хранилища по секции `remote` профиля.
 *
 * Хранилище задаётся профилем, а не общей настройкой: у профилей разное
 * назначение. Боевая копия обязана уезжать с машины, а обезличенная копия для
 * разработки нужна тут же под рукой — платить за её хранение и трафик незачем.
 */
final class RemoteStorageFactory
{
    /** Драйверы, которые пакет умеет собрать. */
    const DRIVERS = ['s3'];

    /**
     * @return \MxBackup\Core\Contract\RemoteStorageInterface|null null — выгрузка выключена.
     */
    public static function create(array $profile)
    {
        $remote = isset($profile['remote']) && is_array($profile['remote']) ? $profile['remote'] : [];
        $driver = isset($remote['driver']) ? trim((string) $remote['driver']) : '';

        if ($driver === '' || $driver === 'none') {
            return null;
        }

        if (!in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException('Неизвестный драйвер удалённого хранилища: ' . $driver);
        }

        $config = isset($remote[$driver]) && is_array($remote[$driver]) ? $remote[$driver] : [];

        return new S3RemoteStorage($config);
    }
}
