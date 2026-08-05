<?php

namespace MxBackup\Core\Config;

final class ConfigValidator
{
    public function validate(array $config)
    {
        $errors = [];
        $profile = isset($config['profile']) && is_array($config['profile']) ? $config['profile'] : [];
        $mode = isset($profile['mode']) ? $profile['mode'] : '';
        if (!in_array($mode, ['prod', 'dev', 'custom'], true)) {
            $errors[] = 'mode должен быть prod, dev или custom';
        }
        $format = isset($profile['format']) ? $profile['format'] : '';
        if (!in_array($format, ['zip', 'tar.gz'], true)) {
            $errors[] = 'format должен быть zip или tar.gz';
        }
        foreach (['files', 'database', 'masking'] as $section) {
            if (!isset($profile[$section]) || !is_array($profile[$section])) {
                $errors[] = 'В профиле отсутствует секция ' . $section;
            }
        }
        if ($mode === 'dev' && empty($profile['masking']['standard'])) {
            $errors[] = 'dev-профиль обязан включать standard masking';
        }

        return $errors;
    }
}
