<?php

namespace MxBackup\Core\Config;

use InvalidArgumentException;
use RuntimeException;

final class ProfileStore
{
    private $directory;

    public function __construct($directory)
    {
        $directory = rtrim(trim((string) $directory), '/\\');
        if ($directory === '') {
            throw new InvalidArgumentException('Каталог профилей не указан.');
        }
        $this->directory = $directory;
    }

    public function getDirectory()
    {
        return $this->directory;
    }

    public function all($activeOnly = false)
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $profiles = [];
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!$this->isValidName($name)) {
                continue;
            }
            $profile = $this->loadFile($file, $name);
            if ($activeOnly && empty($profile['active'])) {
                continue;
            }
            $profiles[$name] = $profile;
        }

        ksort($profiles, SORT_STRING);
        return $profiles;
    }

    public function find($name)
    {
        $name = $this->assertName($name);
        $file = $this->path($name);
        return is_file($file) ? $this->loadFile($file, $name) : null;
    }

    public function save(array $profile, $originalName = null)
    {
        $name = $this->assertName(isset($profile['name']) ? $profile['name'] : '');
        $originalName = $originalName === null || $originalName === ''
            ? $name
            : $this->assertName($originalName);
        $now = time();
        $current = $this->find($originalName);

        $profile['name'] = $name;
        $profile['description'] = isset($profile['description']) ? (string) $profile['description'] : '';
        $profile['mode'] = isset($profile['mode']) ? (string) $profile['mode'] : 'custom';
        $profile['active'] = !empty($profile['active']);
        $profile['createdon'] = isset($profile['createdon']) && (int) $profile['createdon'] > 0
            ? (int) $profile['createdon']
            : ($current && !empty($current['createdon']) ? (int) $current['createdon'] : $now);
        $profile['editedon'] = isset($profile['editedon']) && (int) $profile['editedon'] > 0
            ? (int) $profile['editedon']
            : $now;
        $profile['masking'] = isset($profile['masking']) && is_array($profile['masking'])
            ? $profile['masking']
            : ['standard' => false, 'rules' => []];
        $profile['masking']['rules'] = isset($profile['masking']['rules']) && is_array($profile['masking']['rules'])
            ? array_values($profile['masking']['rules'])
            : [];

        $this->writeFile($this->path($name), $profile);
        if ($originalName !== $name) {
            $oldPath = $this->path($originalName);
            if (is_file($oldPath) && !@unlink($oldPath)) {
                throw new RuntimeException('Не удалось удалить прежний файл профиля: ' . $oldPath);
            }
        }

        return $profile;
    }

    public function remove($name)
    {
        $file = $this->path($this->assertName($name));
        return !is_file($file) || @unlink($file);
    }

    public function addRule($profileName, array $rule)
    {
        $profile = $this->requireProfile($profileName);
        $rules = $this->rules($profile);
        $maxId = 0;
        foreach ($rules as $existing) {
            $maxId = max($maxId, isset($existing['id']) ? (int) $existing['id'] : 0);
        }
        $rule['id'] = $maxId + 1;
        $rule['createdon'] = isset($rule['createdon']) ? (int) $rule['createdon'] : time();
        $rule['editedon'] = isset($rule['editedon']) ? (int) $rule['editedon'] : time();
        $rules[] = $rule;
        $profile['masking']['rules'] = $rules;
        $profile['editedon'] = time();
        $this->save($profile, $profile['name']);
        return $rule;
    }

    public function updateRule($profileName, $ruleId, array $changes)
    {
        $profile = $this->requireProfile($profileName);
        $rules = $this->rules($profile);
        $found = false;
        foreach ($rules as &$rule) {
            if ((int) (isset($rule['id']) ? $rule['id'] : 0) !== (int) $ruleId) {
                continue;
            }
            $changes['id'] = (int) $ruleId;
            $changes['createdon'] = isset($rule['createdon']) ? (int) $rule['createdon'] : time();
            $changes['editedon'] = time();
            $rule = array_merge($rule, $changes);
            $found = true;
            break;
        }
        unset($rule);
        if (!$found) {
            throw new RuntimeException('Правило не найдено.');
        }
        $profile['masking']['rules'] = $rules;
        $profile['editedon'] = time();
        $this->save($profile, $profile['name']);
        return $this->findRule($profile['name'], $ruleId);
    }

    public function removeRule($profileName, $ruleId)
    {
        $profile = $this->requireProfile($profileName);
        $rules = $this->rules($profile);
        $filtered = [];
        $found = false;
        foreach ($rules as $rule) {
            if ((int) (isset($rule['id']) ? $rule['id'] : 0) === (int) $ruleId) {
                $found = true;
                continue;
            }
            $filtered[] = $rule;
        }
        if (!$found) {
            return false;
        }
        $profile['masking']['rules'] = $filtered;
        $profile['editedon'] = time();
        $this->save($profile, $profile['name']);
        return true;
    }

    public function findRule($profileName, $ruleId)
    {
        $profile = $this->find($profileName);
        if (!$profile) {
            return null;
        }
        foreach ($this->rules($profile) as $rule) {
            if ((int) (isset($rule['id']) ? $rule['id'] : 0) === (int) $ruleId) {
                return $rule;
            }
        }
        return null;
    }

    private function requireProfile($name)
    {
        $profile = $this->find($name);
        if (!$profile) {
            throw new RuntimeException('Профиль не найден: ' . $name);
        }
        return $profile;
    }

    private function rules(array $profile)
    {
        return isset($profile['masking']['rules']) && is_array($profile['masking']['rules'])
            ? array_values($profile['masking']['rules'])
            : [];
    }

    private function loadFile($file, $name)
    {
        $profile = (new ConfigLoader())->load($file);
        $profile['name'] = $name;
        $profile['description'] = isset($profile['description']) ? (string) $profile['description'] : '';
        $profile['mode'] = isset($profile['mode']) ? (string) $profile['mode'] : 'custom';
        $profile['active'] = !array_key_exists('active', $profile) || !empty($profile['active']);
        $profile['createdon'] = isset($profile['createdon']) ? (int) $profile['createdon'] : 0;
        $profile['editedon'] = isset($profile['editedon']) ? (int) $profile['editedon'] : 0;
        return $profile;
    }

    private function writeFile($file, array $profile)
    {
        $this->ensureDirectory();
        $temporary = tempnam($this->directory, '.mxbackup-');
        if ($temporary === false) {
            throw new RuntimeException('Не удалось создать временный файл профиля.');
        }
        $source = "<?php\n\nreturn " . var_export($profile, true) . ";\n";
        try {
            if (file_put_contents($temporary, $source, LOCK_EX) === false) {
                throw new RuntimeException('Не удалось записать файл профиля: ' . $file);
            }
            @chmod($temporary, 0640);
            if (!@rename($temporary, $file)) {
                throw new RuntimeException('Не удалось заменить файл профиля: ' . $file);
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function ensureDirectory()
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Не удалось создать каталог профилей: ' . $this->directory);
        }
        if (!is_writable($this->directory)) {
            throw new RuntimeException('Каталог профилей недоступен для записи: ' . $this->directory);
        }
    }

    private function path($name)
    {
        return $this->directory . DIRECTORY_SEPARATOR . $name . '.php';
    }

    private function assertName($name)
    {
        $name = trim((string) $name);
        if (!$this->isValidName($name)) {
            throw new InvalidArgumentException('Допустимы латинские буквы, цифры, _ и -.');
        }
        return $name;
    }

    private function isValidName($name)
    {
        return $name !== '' && preg_match('/^[a-z0-9_-]+$/i', (string) $name) === 1;
    }
}
