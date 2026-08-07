<?php

namespace MxBackup\Tests;

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

    /**
     * @param string|array<int, string> $response
     * @param array<string, string>     $extra
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

    /** @var array<int, string> */
    private $responses;

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

    protected function send($method, $url, array $headers, $uploadFile, $sink)
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;

        if ($this->status < 200 || $this->status >= 300) {
            $body = count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];

            throw new RuntimeException('S3 ответил HTTP ' . $this->status . $this->explainBody($body));
        }

        $body = count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];
        if ($sink !== null) {
            fwrite($sink, $body);

            return '';
        }

        return $body;
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
