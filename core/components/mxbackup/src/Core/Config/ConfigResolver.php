<?php

namespace MxBackup\Core\Config;

use InvalidArgumentException;

final class ConfigResolver
{
    public function resolve(array $defaults, array $system, array $storedProfiles, array $file, array $cli)
    {
        $systemConfig = $this->merge($defaults, $system);
        $config = $this->merge($systemConfig, $file);
        $config = $this->merge($config, $cli);

        $profileName = isset($cli['profile']) && $cli['profile'] !== ''
            ? (string)$cli['profile']
            : (isset($file['profile']) && $file['profile'] !== ''
                ? (string)$file['profile']
                : (string)$systemConfig['default_profile']);

        $knownProfiles = isset($defaults['profiles']) ? $defaults['profiles'] : [];
        $knownProfiles = $this->merge($knownProfiles, $storedProfiles);
        if (isset($file['profiles']) && is_array($file['profiles'])) {
            $knownProfiles = $this->merge($knownProfiles, $file['profiles']);
        }
        if (!isset($knownProfiles[$profileName]) || !is_array($knownProfiles[$profileName])) {
            throw new InvalidArgumentException('Профиль не найден: ' . $profileName);
        }

        $profile = [
            'name' => $profileName,
            'mode' => 'custom',
            'format' => isset($systemConfig['format']) ? $systemConfig['format'] : 'tar.gz',
            'storage_path' => isset($systemConfig['storage_path']) ? $systemConfig['storage_path'] : '',
            'encryption' => ['enabled' => false, 'password' => ''],
            // Скелет секции обязателен: иначе профиль, где `remote` не указана
            // вовсе, читался бы как набор отсутствующих ключей, а не как
            // выключенная выгрузка, и каждое обращение к ней требовало бы isset.
            'remote' => Defaults::remoteTemplate(),
            'files' => ['include' => ['*'], 'exclude' => []],
            'database' => ['include_tables' => ['*'], 'exclude_tables' => []],
            'masking' => ['standard' => false, 'rules' => []],
        ];
        if (isset($defaults['profiles'][$profileName])) {
            $profile = $this->merge($profile, $defaults['profiles'][$profileName]);
        }
        foreach (['storage_path', 'format'] as $key) {
            if (array_key_exists($key, $system) && $system[$key] !== '') {
                $profile[$key] = $system[$key];
            }
        }
        if (isset($storedProfiles[$profileName])) {
            $profile = $this->merge($profile, $storedProfiles[$profileName]);
        }
        if (isset($file['profiles'][$profileName])) {
            $profile = $this->merge($profile, $file['profiles'][$profileName]);
        }

        foreach (['storage_path', 'format'] as $key) {
            if (array_key_exists($key, $file) && $file[$key] !== '') {
                $profile[$key] = $file[$key];
            }
            if (array_key_exists($key, $cli) && $cli[$key] !== '') {
                $profile[$key] = $cli[$key];
            }
        }
        $profile['name'] = $profileName;
        foreach (['mail', 'retention'] as $section) {
            $resolved = isset($systemConfig[$section]) && is_array($systemConfig[$section]) ? $systemConfig[$section] : [];
            if (isset($profile[$section]) && is_array($profile[$section])) {
                $resolved = $this->merge($resolved, $profile[$section]);
            }
            if (isset($file[$section]) && is_array($file[$section])) {
                $resolved = $this->merge($resolved, $file[$section]);
            }
            if (isset($cli[$section]) && is_array($cli[$section])) {
                $resolved = $this->merge($resolved, $cli[$section]);
            }
            $config[$section] = $resolved;
        }
        $config['profiles'] = $knownProfiles;
        $config['profile'] = $profile;

        return $config;
    }

    public function merge(array $base, array $override)
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && $this->isAssociative($value)) {
                $base[$key] = $this->merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function isAssociative(array $value)
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }
}
