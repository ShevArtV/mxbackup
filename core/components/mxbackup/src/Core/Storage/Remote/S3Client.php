<?php

namespace MxBackup\Core\Storage\Remote;

use MxBackup\Core\Progress\ProgressInterface;
use RuntimeException;

/**
 * Минимальный клиент S3 на curl с подписью Signature Version 4.
 *
 * ## Почему не AWS SDK
 *
 * mxBackup ставится transport-архивом и не имеет ни одной зависимости времени
 * выполнения (`composer.json`: только `php >= 7.4`). Тянуть `aws/aws-sdk-php`
 * значило бы либо класть его vendor внутрь пакета, либо требовать composer на
 * каждом сайте, где нужен бэкап в облако. Нужны операции хранения архивов, и
 * подпись SigV4 — единственная нетривиальная часть.
 *
 * ## Что поддерживается
 *
 * PUT (потоково, с диска), multipart PUT с ретраем частей, GET (в файл),
 * DELETE, ListObjectsV2. Хватает, чтобы положить архив, пережить обрыв сети на
 * крупной выгрузке, забрать копию для восстановления, перечислить и удалить
 * лишние по ротации.
 */
class S3Client
{
    const ALGORITHM = 'AWS4-HMAC-SHA256';
    const SERVICE = 's3';
    const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

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

    /** @var int Байты: файл не меньше порога уходит multipart. 0 — всегда single PUT. */
    private $multipartThresholdBytes;

    /** @var int */
    private $partBytes;

    /** @var int */
    private $partRetries;

    /** @var array<string, string> protected ради тестового транспорта. */
    protected $lastResponseHeaders = [];

    public function __construct(array $config, CredentialProvider $credentials = null)
    {
        $this->bucket = isset($config['bucket']) ? (string) $config['bucket'] : '';
        $this->region = isset($config['region']) && $config['region'] !== '' ? (string) $config['region'] : 'us-east-1';
        $this->pathStyle = !empty($config['path_style']);
        $this->timeout = isset($config['timeout']) ? (int) $config['timeout'] : 900;
        $this->credentials = $credentials ?: new CredentialProvider($config);
        $this->multipartThresholdBytes = $this->mbToBytes(
            isset($config['multipart_threshold_mb']) ? $config['multipart_threshold_mb'] : 16
        );
        $this->partBytes = max(
            S3MultipartUploader::MIN_PART_BYTES,
            $this->mbToBytes(isset($config['part_size_mb']) ? $config['part_size_mb'] : 16)
        );
        $this->partRetries = max(0, (int) (isset($config['part_retries']) ? $config['part_retries'] : 3));

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
    public function putFile($key, $path, array $metadata = [], $storageClass = '', callable $checkpoint = null, ProgressInterface $progress = null)
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

        $size = filesize($path);
        if ($size !== false
            && $this->multipartThresholdBytes > 0
            && (int) $size >= $this->multipartThresholdBytes
        ) {
            (new S3MultipartUploader($this, $this->partBytes, $this->partRetries))
                ->upload($key, $path, $headers, $checkpoint, $progress);

            return;
        }

        // Сумма содержимого нужна подписи. Она уже посчитана прогоном (архив
        // проверяется контрольной суммой), но клиент обязан работать и сам по
        // себе — повторный проход по файлу дешевле неверной подписи.
        $payloadHash = isset($metadata['sha256']) && preg_match('/^[a-f0-9]{64}$/i', (string) $metadata['sha256'])
            ? strtolower((string) $metadata['sha256'])
            : $this->hashFile($path, $checkpoint);

        $this->request('PUT', $key, [], $headers, $payloadHash, $path, null, $checkpoint, $progress);
    }

    /**
     * @param array<string, string> $headers
     * @return string UploadId
     */
    public function createMultipartUpload($key, array $headers = [])
    {
        $body = $this->request(
            'POST',
            $key,
            ['uploads' => ''],
            $headers,
            hash('sha256', ''),
            null,
            null,
            null,
            null,
            ''
        );
        $xml = @simplexml_load_string($body);
        if ($xml === false || !isset($xml->UploadId) || (string) $xml->UploadId === '') {
            throw new RuntimeException('S3 не вернул UploadId для multipart.');
        }

        return (string) $xml->UploadId;
    }

    /**
     * @return string ETag части (как отдал S3, обычно в кавычках).
     */
    public function uploadPart(
        $key,
        $uploadId,
        $partNumber,
        $path,
        $offset,
        $length,
        callable $checkpoint = null,
        ProgressInterface $progress = null
    ) {
        $this->request(
            'PUT',
            $key,
            [
                'partNumber' => (string) (int) $partNumber,
                'uploadId' => (string) $uploadId,
            ],
            [],
            self::UNSIGNED_PAYLOAD,
            [
                'path' => $path,
                'offset' => (int) $offset,
                'length' => (int) $length,
            ],
            null,
            $checkpoint,
            $progress
        );

        $etag = $this->responseHeader('etag');
        if ($etag === '') {
            throw new RuntimeException('S3 не вернул ETag для части ' . $partNumber);
        }

        return $etag;
    }

    /**
     * @param array<int, array{part_number: int, etag: string}> $parts
     */
    public function completeMultipartUpload($key, $uploadId, array $parts)
    {
        $xml = '<CompleteMultipartUpload>';
        foreach ($parts as $part) {
            $xml .= '<Part><PartNumber>' . (int) $part['part_number'] . '</PartNumber>'
                . '<ETag>' . htmlspecialchars((string) $part['etag'], ENT_XML1) . '</ETag></Part>';
        }
        $xml .= '</CompleteMultipartUpload>';

        $this->request(
            'POST',
            $key,
            ['uploadId' => (string) $uploadId],
            ['content-type' => 'application/xml'],
            hash('sha256', $xml),
            null,
            null,
            null,
            null,
            $xml
        );
    }

    public function abortMultipartUpload($key, $uploadId)
    {
        $this->request(
            'DELETE',
            $key,
            ['uploadId' => (string) $uploadId],
            [],
            hash('sha256', '')
        );
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
     * @param array<string, string>     $query
     * @param array<string, string>     $headers
     * @param string|array|null         $upload Файл или ['path','offset','length'].
     * @param resource|null             $sink
     * @param string|null               $body   Тело запроса без файла (XML complete).
     */
    private function request(
        $method,
        $key,
        array $query,
        array $headers,
        $payloadHash,
        $upload = null,
        $sink = null,
        callable $checkpoint = null,
        ProgressInterface $progress = null,
        $body = null
    ) {
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

        return $this->send($method, $url, $requestHeaders, $upload, $sink, $checkpoint, $progress, $body);
    }

    /**
     * Отправка подписанного запроса.
     *
     * protected, а не private, ради тестов: подмена транспорта — единственный
     * способ проверить саму подпись, не выходя в сеть.
     *
     * @param array<int, string>        $headers
     * @param string|array|null         $upload
     * @param resource|null             $sink
     * @param string|null               $body
     */
    protected function send(
        $method,
        $url,
        array $headers,
        $upload,
        $sink,
        callable $checkpoint = null,
        ProgressInterface $progress = null,
        $body = null
    ) {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Для работы с S3 нужен модуль curl.');
        }

        $this->lastResponseHeaders = [];
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($curl, CURLOPT_FAILONERROR, false);
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($ch, $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && strpos($trimmed, ':') !== false) {
                [$name, $value] = array_map('trim', explode(':', $trimmed, 2));
                $this->lastResponseHeaders[strtolower($name)] = $value;
            }

            return strlen($line);
        });
        if ($checkpoint || $progress) {
            curl_setopt($curl, CURLOPT_NOPROGRESS, false);
            $sent = 0;
            // curl отдаёт накопленный итог, а прогрессу нужна дельта. При
            // повторной отправке (redirect, retry внутри curl) счётчик
            // начинается заново — такой откат считаем новым стартом, иначе
            // дельта уйдёт в минус.
            $callback = static function ($resource, $dltotal, $dlnow, $ultotal, $ulnow) use ($checkpoint, $progress, &$sent) {
                if ($checkpoint) {
                    $checkpoint();
                }
                if ($progress) {
                    $ulnow = (int) $ulnow;
                    $delta = $ulnow >= $sent ? $ulnow - $sent : $ulnow;
                    $sent = $ulnow;
                    if ($delta > 0) {
                        $progress->advance($delta);
                    }
                }

                return 0;
            };
            if (defined('CURLOPT_XFERINFOFUNCTION')) {
                curl_setopt($curl, CURLOPT_XFERINFOFUNCTION, $callback);
            } else {
                curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, $callback);
            }
        }

        $handle = null;
        if (is_array($upload)) {
            $path = isset($upload['path']) ? (string) $upload['path'] : '';
            $offset = isset($upload['offset']) ? (int) $upload['offset'] : 0;
            $length = isset($upload['length']) ? (int) $upload['length'] : 0;
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Не удалось открыть файл: ' . $path);
            }
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                fclose($handle);
                throw new RuntimeException('Не удалось спозиционировать чтение части файла: ' . $path);
            }
            $remaining = $length;
            curl_setopt($curl, CURLOPT_UPLOAD, true);
            curl_setopt($curl, CURLOPT_INFILESIZE, $length);
            curl_setopt($curl, CURLOPT_READFUNCTION, static function ($ch, $fd, $want) use ($handle, &$remaining) {
                if ($remaining <= 0) {
                    return '';
                }
                $chunk = fread($handle, min($want, $remaining));
                if ($chunk === false || $chunk === '') {
                    return '';
                }
                $remaining -= strlen($chunk);

                return $chunk;
            });
        } elseif ($upload !== null) {
            $handle = fopen($upload, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Не удалось открыть файл: ' . $upload);
            }
            // Потоковая отправка: архив в 150 МБ не должен оказаться в памяти
            // PHP-процесса целиком — memory_limit на хостингах меньше.
            curl_setopt($curl, CURLOPT_PUT, true);
            curl_setopt($curl, CURLOPT_INFILE, $handle);
            curl_setopt($curl, CURLOPT_INFILESIZE, filesize($upload));
        } elseif ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        if ($sink !== null) {
            curl_setopt($curl, CURLOPT_FILE, $sink);
        } else {
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        }

        if ($method === 'HEAD') {
            curl_setopt($curl, CURLOPT_NOBODY, true);
        }

        try {
            $responseBody = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
        } finally {
            curl_close($curl);
            if ($handle !== null) {
                fclose($handle);
            }
        }

        if ($responseBody === false && $error !== '') {
            throw new RuntimeException('Запрос к S3 не выполнен: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('S3 ответил HTTP ' . $status . $this->explain(is_string($responseBody) ? $responseBody : ''));
        }

        return is_string($responseBody) ? $responseBody : '';
    }

    private function responseHeader($name)
    {
        $key = strtolower((string) $name);

        return isset($this->lastResponseHeaders[$key]) ? $this->lastResponseHeaders[$key] : '';
    }

    private function mbToBytes($mb)
    {
        $mb = (float) $mb;
        if ($mb <= 0) {
            return 0;
        }

        return (int) round($mb * 1048576);
    }

    private function hashFile($path, callable $checkpoint = null)
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Файл недоступен для SHA-256: ' . $path);
        }
        $context = hash_init('sha256');
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Ошибка чтения файла для SHA-256: ' . $path);
                }
                if ($chunk !== '') {
                    hash_update($context, $chunk);
                    if ($checkpoint) {
                        $checkpoint();
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return hash_final($context);
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
            // Пустое значение у `uploads` в CreateMultipartUpload — это
            // `uploads=`, а не отсутствие параметра.
            $parts[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $parts);
    }
}
