<?php

namespace MxBackup\Core\Storage\Remote;

use RuntimeException;

/**
 * Минимальный клиент S3 на curl с подписью Signature Version 4.
 *
 * ## Почему не AWS SDK
 *
 * mxBackup ставится transport-архивом и не имеет ни одной зависимости времени
 * выполнения (`composer.json`: только `php >= 7.4`). Тянуть `aws/aws-sdk-php`
 * значило бы либо класть его vendor внутрь пакета, либо требовать composer на
 * каждом сайте, где нужен бэкап в облако. Нужны четыре операции, и подпись
 * SigV4 — единственная нетривиальная часть.
 *
 * ## Что поддерживается
 *
 * PUT (потоково, с диска), GET (в файл), DELETE, ListObjectsV2. Хватает, чтобы
 * положить архив, забрать его для восстановления, перечислить копии и удалить
 * лишние по ротации. Multipart-загрузка не реализована: предел одиночного PUT —
 * 5 ГБ, а архив такого размера в PHP-процессе не соберётся раньше по другим
 * причинам (память, время, лимиты хостера).
 */
class S3Client
{
    const ALGORITHM = 'AWS4-HMAC-SHA256';
    const SERVICE = 's3';

    /** @var string */
    private $bucket;

    /** @var string */
    private $region;

    /** @var string Базовый адрес без завершающего слэша. */
    private $endpoint;

    /** @var bool Ключ в пути (MinIO и совместимые), а не в имени хоста. */
    private $pathStyle;

    /** @var CredentialProvider */
    private $credentials;

    /** @var int */
    private $timeout;

    public function __construct(array $config, CredentialProvider $credentials = null)
    {
        $this->bucket = isset($config['bucket']) ? (string) $config['bucket'] : '';
        $this->region = isset($config['region']) && $config['region'] !== '' ? (string) $config['region'] : 'us-east-1';
        $this->pathStyle = !empty($config['path_style']);
        $this->timeout = isset($config['timeout']) ? (int) $config['timeout'] : 900;
        $this->credentials = $credentials ?: new CredentialProvider($config);

        if ($this->bucket === '') {
            throw new RuntimeException('Не задан бакет S3.');
        }

        $endpoint = isset($config['endpoint']) ? trim((string) $config['endpoint']) : '';
        if ($endpoint === '') {
            // Virtual-hosted style: имя бакета в хосте. Path-style в AWS объявлен
            // устаревшим, но для S3-совместимых хранилищ остаётся единственным.
            $this->endpoint = 'https://' . $this->bucket . '.s3.' . $this->region . '.amazonaws.com';
        } else {
            $this->endpoint = rtrim($endpoint, '/');
            $this->pathStyle = true;
        }
    }

    /**
     * Загрузить файл под ключом.
     *
     * @param array<string, string> $metadata Пользовательские метаданные (x-amz-meta-*).
     */
    public function putFile($key, $path, array $metadata = [], $storageClass = '')
    {
        if (!is_readable($path)) {
            throw new RuntimeException('Файл недоступен для чтения: ' . $path);
        }

        $headers = ['content-type' => 'application/octet-stream'];
        foreach ($metadata as $name => $value) {
            $headers['x-amz-meta-' . strtolower((string) $name)] = (string) $value;
        }
        if ($storageClass !== '') {
            $headers['x-amz-storage-class'] = $storageClass;
        }

        // Сумма содержимого нужна подписи. Она уже посчитана прогоном (архив
        // проверяется контрольной суммой), но клиент обязан работать и сам по
        // себе — повторный проход по файлу дешевле неверной подписи.
        $payloadHash = hash_file('sha256', $path);

        $this->request('PUT', $key, [], $headers, $payloadHash, $path);
    }

    /**
     * Скачать объект в файл.
     */
    public function getObject($key, $target)
    {
        $handle = fopen($target, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Не удалось открыть файл для записи: ' . $target);
        }

        try {
            $this->request('GET', $key, [], [], hash('sha256', ''), null, $handle);
        } catch (RuntimeException $e) {
            fclose($handle);
            @unlink($target);
            throw $e;
        }

        fclose($handle);

        return $target;
    }

    public function deleteObject($key)
    {
        $this->request('DELETE', $key, [], [], hash('sha256', ''));
    }

    /**
     * Перечислить объекты по префиксу.
     *
     * @return array<int, array{key: string, size: int, modified: int}>
     */
    public function listObjects($prefix = '')
    {
        $objects = [];
        $token = null;

        // Ответ ограничен тысячей ключей: без обхода страниц ротация в бакете с
        // историей просто не увидела бы старые копии и никогда бы их не удалила.
        do {
            $query = ['list-type' => '2', 'prefix' => $prefix];
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }

            $body = $this->request('GET', '', $query, [], hash('sha256', ''));
            $xml = @simplexml_load_string($body);
            if ($xml === false) {
                throw new RuntimeException('Ответ S3 не разобран как XML.');
            }

            foreach ($xml->Contents as $item) {
                $objects[] = [
                    'key' => (string) $item->Key,
                    'size' => (int) $item->Size,
                    'modified' => (int) strtotime((string) $item->LastModified),
                ];
            }

            $token = ((string) $xml->IsTruncated === 'true') ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');

        return $objects;
    }

    /**
     * Есть ли объект. Отсутствие — не ошибка, поэтому 404 отделяется от прочих.
     */
    public function exists($key)
    {
        try {
            $this->request('HEAD', $key, [], [], hash('sha256', ''));

            return true;
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'HTTP 404') !== false) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Подписанный запрос.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param string|null           $uploadFile Файл-источник для PUT.
     * @param resource|null         $sink       Куда писать тело ответа.
     */
    private function request($method, $key, array $query, array $headers, $payloadHash, $uploadFile = null, $sink = null)
    {
        $credentials = $this->credentials->resolve();
        $timestamp = gmdate('Ymd\THis\Z');
        $date = substr($timestamp, 0, 8);

        $path = $this->canonicalPath($key);
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        if ($port) {
            $host .= ':' . $port;
        }

        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers['host'] = $host;
        $headers['x-amz-date'] = $timestamp;
        $headers['x-amz-content-sha256'] = $payloadHash;
        if ($credentials->getToken() !== '') {
            $headers['x-amz-security-token'] = $credentials->getToken();
        }

        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim((string) $value) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [
            $method,
            $path,
            $this->canonicalQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = $date . '/' . $this->region . '/' . self::SERVICE . '/aws4_request';
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $timestamp,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($credentials->getSecret(), $date));
        $authorization = self::ALGORITHM
            . ' Credential=' . $credentials->getKey() . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        $requestHeaders = ['Authorization: ' . $authorization];
        foreach ($headers as $name => $value) {
            if ($name !== 'host') {
                $requestHeaders[] = $name . ': ' . $value;
            }
        }

        $url = $this->endpoint . $path;
        if ($query) {
            $url .= '?' . $this->canonicalQuery($query);
        }

        return $this->send($method, $url, $requestHeaders, $uploadFile, $sink);
    }

    /**
     * Отправка подписанного запроса.
     *
     * protected, а не private, ради тестов: подмена транспорта — единственный
     * способ проверить саму подпись, не выходя в сеть.
     *
     * @param array<int, string> $headers
     * @param string|null        $uploadFile
     * @param resource|null      $sink
     */
    protected function send($method, $url, array $headers, $uploadFile, $sink)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Для работы с S3 нужен модуль curl.');
        }

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($curl, CURLOPT_FAILONERROR, false);

        $handle = null;
        if ($uploadFile !== null) {
            $handle = fopen($uploadFile, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Не удалось открыть файл: ' . $uploadFile);
            }
            // Потоковая отправка: архив в 150 МБ не должен оказаться в памяти
            // PHP-процесса целиком — memory_limit на хостингах меньше.
            curl_setopt($curl, CURLOPT_PUT, true);
            curl_setopt($curl, CURLOPT_INFILE, $handle);
            curl_setopt($curl, CURLOPT_INFILESIZE, filesize($uploadFile));
        }

        if ($sink !== null) {
            curl_setopt($curl, CURLOPT_FILE, $sink);
        } else {
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        }

        if ($method === 'HEAD') {
            curl_setopt($curl, CURLOPT_NOBODY, true);
        }

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($handle !== null) {
            fclose($handle);
        }

        if ($body === false && $error !== '') {
            throw new RuntimeException('Запрос к S3 не выполнен: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('S3 ответил HTTP ' . $status . $this->explain(is_string($body) ? $body : ''));
        }

        return is_string($body) ? $body : '';
    }

    /**
     * Человекочитаемая часть ошибки S3: код и сообщение из XML-ответа.
     */
    private function explain($body)
    {
        if (trim($body) === '') {
            return '';
        }

        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            return '';
        }

        $code = isset($xml->Code) ? (string) $xml->Code : '';
        $message = isset($xml->Message) ? (string) $xml->Message : '';
        $text = trim($code . ($code !== '' && $message !== '' ? ': ' : '') . $message);

        return $text === '' ? '' : ' (' . $text . ')';
    }

    private function signingKey($secret, $date)
    {
        $key = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', self::SERVICE, $key, true);

        return hash_hmac('sha256', 'aws4_request', $key, true);
    }

    /**
     * Путь запроса. Каждый сегмент кодируется по RFC 3986, слэши остаются:
     * ключ `backups/2026/архив.zip` — три сегмента, а не одна строка.
     */
    private function canonicalPath($key)
    {
        $key = ltrim((string) $key, '/');
        $prefix = $this->pathStyle ? '/' . $this->bucket : '';

        if ($key === '') {
            return $prefix === '' ? '/' : $prefix . '/';
        }

        $segments = array_map('rawurlencode', explode('/', $key));

        return $prefix . '/' . implode('/', $segments);
    }

    /**
     * @param array<string, string> $query
     */
    private function canonicalQuery(array $query)
    {
        if (!$query) {
            return '';
        }

        ksort($query);
        $parts = [];
        foreach ($query as $name => $value) {
            $parts[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $parts);
    }
}
