<?php

namespace MxBackup\Core\Storage\Remote;

use RuntimeException;

/**
 * Откуда берутся ключи для S3 — по порядку убывания явности.
 *
 * 1. Профиль. Самый явный способ; годится там, где сайт живёт не в AWS.
 *    ⚠️ Ключ окажется в PHP-файле профиля — как и пароль AES-шифрования, это
 *    осознанный компромисс, и права на файл должны быть 0640.
 * 2. Переменные окружения. Обычный путь для контейнеров и CI.
 * 3. Роль инстанса через IMDSv2. Единственный способ, при котором ключей на
 *    машине нет вовсе: метаданные отдают временные данные на час, и утечка
 *    архива конфигурации не даёт доступа к бакету.
 *
 * Порядок именно такой: явно заданное всегда должно побеждать унаследованное
 * из окружения, иначе непонятно, чем именно подписан запрос.
 */
final class CredentialProvider
{
    /** Адрес сервиса метаданных EC2. Ссылка на него не резолвится через DNS. */
    const METADATA_HOST = 'http://169.254.169.254';

    /** @var array<string, mixed> Секция s3 профиля. */
    private $config;

    /** @var Credentials|null Кэш на время процесса. */
    private $cached;

    /** @var callable|null Подмена HTTP-запроса к метаданным (для тестов). */
    private $metadataFetcher;

    public function __construct(array $config, $metadataFetcher = null)
    {
        $this->config = $config;
        $this->metadataFetcher = $metadataFetcher;
    }

    public function resolve()
    {
        if ($this->cached !== null && !$this->cached->isExpired()) {
            return $this->cached;
        }

        $explicit = new Credentials(
            isset($this->config['access_key']) ? $this->config['access_key'] : '',
            isset($this->config['secret_key']) ? $this->config['secret_key'] : '',
            isset($this->config['session_token']) ? $this->config['session_token'] : ''
        );
        if (!$explicit->isEmpty()) {
            return $this->cached = $explicit;
        }

        $environment = new Credentials(
            (string) getenv('AWS_ACCESS_KEY_ID'),
            (string) getenv('AWS_SECRET_ACCESS_KEY'),
            (string) getenv('AWS_SESSION_TOKEN')
        );
        if (!$environment->isEmpty()) {
            return $this->cached = $environment;
        }

        $role = $this->fromInstanceRole();
        if ($role !== null) {
            return $this->cached = $role;
        }

        throw new RuntimeException(
            'Не удалось получить ключи AWS: ни в профиле, ни в переменных окружения '
            . '(AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY), ни у роли инстанса.'
        );
    }

    /**
     * Временные данные роли инстанса.
     *
     * IMDSv2 требует сначала взять токен PUT-запросом: версия 1 отвечала на
     * простой GET, и любая SSRF-уязвимость на сайте превращалась в кражу ключей
     * роли. На инстансах, где v1 отключён (у нас — да), без токена не ответят.
     */
    private function fromInstanceRole()
    {
        $token = $this->request('PUT', self::METADATA_HOST . '/latest/api/token', [
            'X-aws-ec2-metadata-token-ttl-seconds: 60',
        ]);
        if ($token === null || trim($token) === '') {
            return null;
        }

        $headers = ['X-aws-ec2-metadata-token: ' . trim($token)];
        $role = $this->request('GET', self::METADATA_HOST . '/latest/meta-data/iam/security-credentials/', $headers);
        if ($role === null || trim($role) === '') {
            return null;
        }

        // В списке может быть несколько строк; берётся первая — у инстанса
        // всё равно один профиль, а вторая строка означала бы неоднозначность.
        $role = trim(strtok(trim($role), "\n"));
        $body = $this->request(
            'GET',
            self::METADATA_HOST . '/latest/meta-data/iam/security-credentials/' . rawurlencode($role),
            $headers
        );
        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['AccessKeyId']) || empty($data['SecretAccessKey'])) {
            return null;
        }

        $expires = isset($data['Expiration']) ? strtotime((string) $data['Expiration']) : null;

        return new Credentials(
            $data['AccessKeyId'],
            $data['SecretAccessKey'],
            isset($data['Token']) ? $data['Token'] : '',
            $expires ?: null
        );
    }

    /**
     * @param array<int, string> $headers
     * @return string|null Тело ответа либо null при любой неудаче.
     */
    private function request($method, $url, array $headers)
    {
        if ($this->metadataFetcher !== null) {
            return call_user_func($this->metadataFetcher, $method, $url, $headers);
        }

        if (!function_exists('curl_init')) {
            return null;
        }

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        // Сервис метаданных отвечает мгновенно либо не отвечает вовсе: машина
        // не в AWS. Секунда таймаута — чтобы бэкап не ждал впустую.
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($curl, CURLOPT_TIMEOUT, 2);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return ($body === false || $status < 200 || $status >= 300) ? null : (string) $body;
    }
}
