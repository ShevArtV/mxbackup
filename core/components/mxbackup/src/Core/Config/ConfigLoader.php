<?php

namespace MxBackup\Core\Config;

use RuntimeException;

final class ConfigLoader
{
    public function load($path)
    {
        $path = trim((string)$path);
        if ($path === '') {
            return [];
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Файл конфигурации недоступен: ' . $path);
        }

        $config = include $path;
        if (!is_array($config)) {
            throw new RuntimeException('Файл конфигурации должен возвращать массив: ' . $path);
        }

        return $config;
    }
}
