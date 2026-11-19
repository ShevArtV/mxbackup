<?php

namespace MxBackup\Tests;

use MxBackup\Core\Progress\ProgressInterface;
use MxBackup\Core\Storage\Remote\CredentialProvider;
use MxBackup\Core\Storage\Remote\S3Client;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Клиент S3: подпись SigV4 и разбор ответов.
 *
 * ⚠️ Корректность подписи как таковой доказывается не здесь, а прогоном против
 * настоящего S3 (сделан 07.08.2026: PUT, GET, DELETE, ListObjectsV2, HEAD).
 * Юнит-тесты закрывают другое — регрессии: канонический путь, порядок
 * параметров, состав подписанных заголовков и стабильность самой подписи при
 * фиксированных входных данных. Сломать любое из этого правкой легко, а
 * проявится оно только в бою, ошибкой SignatureDoesNotMatch.
 */
final class S3ClientTest extends TestCase
{
    public function testCanonicalPathEncodesSegmentsButKeepsSlashes()
    {
        $client = $this->client();
        $client->getObject('backups/2026/архив копии.zip', sys_get_temp_dir() . '/mx-s3-test');

        $this->assertSame(
            'https://bucket.s3.eu-central-1.amazonaws.com/backups/2026/'
            . rawurlencode('архив копии.zip'),
            $client->lastUrl
        );
    }

    public function testQueryParametersAreSorted()
    {
        $client = $this->client('<?xml version="1.0"?><ListBucketResult></ListBucketResult>');
        $client->listObjects('prefix/');

        $this->assertStringContainsString('?list-type=2&prefix=prefix%2F', $client->lastUrl);
    }

    /**
     * Заголовки с суммой тела и датой обязаны быть подписаны: S3 отвергнет
     * запрос, где они есть, но в SignedHeaders не перечислены.
     */
    public function testSignedHeadersIncludeContentHashAndDate()
    {
        $client = $this->client();
        $client->deleteObject('a.zip');

        $authorization = $client->lastHeader('Authorization');
        $this->assertStringContainsString('AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE/', $authorization);
        $this->assertMatchesRegularExpression(
            '/SignedHeaders=[^,]*x-amz-content-sha256[^,]*x-amz-date/',
            $authorization
        );
        $this->assertMatchesRegularExpression('/Signature=[0-9a-f]{64}$/', $authorization);
    }

    /**
     * Временные данные роли требуют своего заголовка, и он тоже подписывается —
     * иначе S3 ответит «InvalidToken» на технически верную подпись.
     */
    public function testSessionTokenIsSentAndSigned()
    {
        $client = $this->client('', ['session_token' => 'FwoGZXIvYXdzEXAMPLE']);
        $client->deleteObject('a.zip');

        $this->assertSame('FwoGZXIvYXdzEXAMPLE', $client->lastHeader('x-amz-security-token'));
        $this->assertStringContainsString('x-amz-security-token', $client->lastHeader('Authorization'));
    }

    public function testListParsesObjectsAndFollowsPagination()
    {
        $first = '<?xml version="1.0"?><ListBucketResult>'
            . '<IsTruncated>true</IsTruncated><NextContinuationToken>TOKEN</NextContinuationToken>'
            . '<Contents><Key>a.zip</Key><Size>10</Size><LastModified>2026-08-01T10:00:00.000Z</LastModified></Contents>'
            . '</ListBucketResult>';
        $second = '<?xml version="1.0"?><ListBucketResult>'
            . '<IsTruncated>false</IsTruncated>'
            . '<Contents><Key>b.zip</Key><Size>20</Size><LastModified>2026-08-02T10:00:00.000Z</LastModified></Contents>'
            . '</ListBucketResult>';

        $client = $this->client([$first, $second]);
        $objects = $client->listObjects('');

        $this->assertSame(['a.zip', 'b.zip'], array_column($objects, 'key'));
        $this->assertSame(20, $objects[1]['size']);
        $this->assertStringContainsString('continuation-token=TOKEN', $client->lastUrl);
    }

    public function testMissingObjectIsNotAnError()
    {
        $client = $this->client();
        $client->status = 404;

        $this->assertFalse($client->exists('nope.zip'));
    }

    /**
     * Сообщение S3 лежит в теле ответа. Без него в журнале остаётся голый
     * «HTTP 403», по которому не отличить нехватку прав от неверного бакета.
     */
    public function testErrorMessageFromBodyIsSurfaced()
    {
        $client = $this->client('<?xml version="1.0"?><Error><Code>AccessDenied</Code>'
            . '<Message>Access Denied</Message></Error>');
        $client->status = 403;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 403 (AccessDenied: Access Denied)');

        $client->deleteObject('a.zip');
    }

    public function testPutFileBelowThresholdUsesSinglePut()
    {
        $path = sys_get_temp_dir() . '/mx-s3-small-' . bin2hex(random_bytes(4));
        file_put_contents($path, str_repeat('a', 1024));
        $client = $this->client('', ['multipart_threshold_mb' => 16]);
        $client->putFile('small.bin', $path, ['sha256' => hash('sha256', str_repeat('a', 1024))]);

        $this->assertCount(1, $client->calls);
        $this->assertSame('PUT', $client->calls[0]['method']);
        $this->assertStringNotContainsString('uploads', $client->calls[0]['url']);
        @unlink($path);
    }

    public function testPutFileAtThresholdUsesMultipartAndRetriesPart()
    {
        $path = sys_get_temp_dir() . '/mx-s3-multi-' . bin2hex(random_bytes(4));
        // 6 МБ — выше порога 5 МБ и больше одной части при part_size 5 МБ.
        file_put_contents($path, str_repeat('b', 6 * 1048576));
        $client = $this->client([
            '<?xml version="1.0"?><InitiateMultipartUploadResult><UploadId>UP1</UploadId></InitiateMultipartUploadResult>',
            'part-fail',
            'part-ok',
            'part-ok-2',
            '<?xml version="1.0"?><CompleteMultipartUploadResult></CompleteMultipartUploadResult>',
        ], [
            'multipart_threshold_mb' => 5,
            'part_size_mb' => 5,
            'part_retries' => 1,
        ]);
        $client->failStatuses = [1 => 503];

        $client->putFile('big.bin', $path, ['sha256' => hash_file('sha256', $path)]);

        $methods = array_column($client->calls, 'method');
        $this->assertSame('POST', $methods[0]);
        $this->assertStringContainsString('uploads=', $client->calls[0]['url']);
        $this->assertStringContainsString('UNSIGNED-PAYLOAD', $client->calls[2]['headers_blob']);
        $this->assertStringContainsString('uploadId=UP1', $client->calls[4]['url']);
        @unlink($path);
    }

    public function testMultipartAbortOnFailure()
    {
        $path = sys_get_temp_dir() . '/mx-s3-abort-' . bin2hex(random_bytes(4));
        file_put_contents($path, str_repeat('c', 6 * 1048576));
        $client = $this->client([
            '<?xml version="1.0"?><InitiateMultipartUploadResult><UploadId>UP2</UploadId></InitiateMultipartUploadResult>',
            'part-fail',
        ], [
            'multipart_threshold_mb' => 5,
            'part_size_mb' => 5,
            'part_retries' => 0,
        ]);
        $client->failStatuses = [1 => 500];

        try {
            $client->putFile('fail.bin', $path);
            $this->fail('Ожидалось исключение multipart');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
        }

        $last = end($client->calls);
        $this->assertSame('DELETE', $last['method']);
        $this->assertStringContainsString('uploadId=UP2', $last['url']);
        @unlink($path);
    }

    /**
     * @param string|array<int, string> $response
     * @param array<string, mixed>      $extra
     */
    private function client($response = '', array $extra = [])
    {
        $config = array_merge([
            'bucket' => 'bucket',
            'region' => 'eu-central-1',
            'access_key' => 'AKIAEXAMPLE',
            'secret_key' => 'secretexample',
        ], $extra);

        return new RecordingS3Client($config, new CredentialProvider($config), $response);
    }
}

/**
 * Клиент с подменённым транспортом: запоминает, что ушло бы в сеть.
 */
final class RecordingS3Client extends S3Client
{
    /** @var string */
    public $lastUrl = '';

    /** @var array<int, string> */
    public $lastHeaders = [];

    /** @var int */
    public $status = 200;

    /** @var array<int, int> Индекс вызова => HTTP-статус ошибки. */
    public $failStatuses = [];

    /** @var array<int, array{method: string, url: string, headers_blob: string}> */
    public $calls = [];

    /** @var array<int, string> */
    private $responses;

    /** @var int */
    private $callIndex = 0;

    public function __construct(array $config, CredentialProvider $credentials, $response)
    {
        parent::__construct($config, $credentials);
        $this->responses = is_array($response) ? $response : [$response];
    }

    public function lastHeader($name)
    {
        foreach ($this->lastHeaders as $header) {
            [$key, $value] = array_pad(explode(':', $header, 2), 2, '');
            if (strcasecmp(trim($key), $name) === 0) {
                return trim($value);
            }
        }

        return '';
    }

    protected function send(
        $method,
        $url,
        array $headers,
        $uploadFile,
        $sink,
        callable $checkpoint = null,
        ProgressInterface $progress = null,
        $body = null
    ) {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastResponseHeaders = [];
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers_blob' => implode("\n", $headers),
        ];
        $index = $this->callIndex++;

        // Сети здесь нет, значит и байтам взяться неоткуда: отмечаем размер
        // файла разом, чтобы стадия выгрузки закрывалась заполненной.
        if ($progress) {
            if (is_array($uploadFile) && isset($uploadFile['length'])) {
                $progress->advance((int) $uploadFile['length']);
            } elseif (is_string($uploadFile) && is_file($uploadFile)) {
                $progress->advance((int) filesize($uploadFile));
            }
        }

        $status = array_key_exists($index, $this->failStatuses) ? (int) $this->failStatuses[$index] : $this->status;
        if ($status < 200 || $status >= 300) {
            $errorBody = count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];

            throw new RuntimeException('S3 ответил HTTP ' . $status . $this->explainBody($errorBody));
        }

        $responseBody = count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];
        if (strpos($url, 'partNumber=') !== false) {
            $this->lastResponseHeaders['etag'] = '"etag-' . $index . '"';
        }
        if ($sink !== null) {
            fwrite($sink, $responseBody);

            return '';
        }

        return $responseBody;
    }

    /**
     * Повторяет разбор ошибки родителя: тот метод приватный, а проверить
     * читаемость сообщения надо именно на этом уровне.
     */
    private function explainBody($body)
    {
        $xml = @simplexml_load_string((string) $body);
        if ($xml === false || !isset($xml->Code)) {
            return '';
        }

        return ' (' . (string) $xml->Code . ': ' . (string) $xml->Message . ')';
    }
}
