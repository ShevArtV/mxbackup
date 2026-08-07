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
        $encryption = isset($profile['encryption']) && is_array($profile['encryption'])
            ? $profile['encryption']
            : [];
        if (!empty($encryption['enabled'])) {
            if ($format !== 'zip') {
                $errors[] = 'шифрование доступно только для ZIP';
            }
            if (!isset($encryption['password']) || (string) $encryption['password'] === '') {
                $errors[] = 'для шифрования требуется пароль';
            }
            if (!class_exists('ZipArchive')
                || !method_exists('ZipArchive', 'setEncryptionName')
                || !defined('ZipArchive::EM_AES_256')) {
                $errors[] = 'текущая сборка ext-zip не поддерживает AES-256';
            }
        }
        foreach (['files', 'database', 'masking'] as $section) {
            if (!isset($profile[$section]) || !is_array($profile[$section])) {
                $errors[] = 'В профиле отсутствует секция ' . $section;
            }
        }
        if ($mode === 'dev' && empty($profile['masking']['standard'])) {
            $errors[] = 'dev-профиль обязан включать standard masking';
        }
        foreach ($this->validateRemote($profile) as $error) {
            $errors[] = $error;
        }

        return $errors;
    }

    /**
     * Секция удалённого хранилища.
     *
     * Проверяется до прогона и намеренно строго: ошибка в этих полях всплывает
     * иначе только в конце копирования, когда база уже выгружена и архив
     * упакован, — то есть после самой долгой части работы.
     *
     * @return array<int, string>
     */
    private function validateRemote(array $profile)
    {
        $remote = isset($profile['remote']) && is_array($profile['remote']) ? $profile['remote'] : [];
        $driver = isset($remote['driver']) ? trim((string) $remote['driver']) : '';
        if ($driver === '' || $driver === 'none') {
            return [];
        }

        $errors = [];
        if ($driver !== 's3') {
            return ['remote.driver: поддерживается только s3 (либо пусто — выгрузка выключена)'];
        }

        $s3 = isset($remote['s3']) && is_array($remote['s3']) ? $remote['s3'] : [];
        if (empty($s3['bucket'])) {
            $errors[] = 'remote.s3.bucket: не задан бакет';
        }
        // Регион входит в подпись SigV4, и неверный даёт SignatureDoesNotMatch —
        // ошибку, по которой не видно, что дело в регионе. Для совместимых
        // хранилищ он тоже нужен, пусть и формально.
        if (empty($s3['region'])) {
            $errors[] = 'remote.s3.region: не задан регион (для S3-совместимых подойдёт любой, например us-east-1)';
        }
        if (!empty($s3['prefix']) && strpos((string) $s3['prefix'], '..') !== false) {
            $errors[] = 'remote.s3.prefix: путь не должен содержать ..';
        }
        // Ключ без секрета (и наоборот) — частая опечатка при заполнении формы:
        // цепочка поиска в таком случае молча уйдёт к переменным окружения или
        // роли инстанса, и запрос уйдёт не от того, кого настраивали.
        $hasKey = !empty($s3['access_key']);
        $hasSecret = !empty($s3['secret_key']);
        if ($hasKey !== $hasSecret) {
            $errors[] = 'remote.s3: заполните обе половины ключа либо оставьте оба поля пустыми'
                . ' (тогда доступ возьмётся из окружения или роли инстанса)';
        }
        if (isset($remote['keep_local']) && (int) $remote['keep_local'] < 1) {
            $errors[] = 'remote.keep_local: минимум 1 — последняя копия остаётся на диске всегда';
        }

        return $errors;
    }
}
