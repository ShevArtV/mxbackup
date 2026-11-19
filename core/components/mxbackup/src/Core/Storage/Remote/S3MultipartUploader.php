<?php

namespace MxBackup\Core\Storage\Remote;

use MxBackup\Core\Progress\ProgressInterface;
use RuntimeException;

/**
 * Multipart-выгрузка файла в S3 с ретраем отдельных частей.
 *
 * Одиночный PUT переживает обрыв сети только целиком. Здесь упавший кусок
 * повторяется, уже принятые части не переотправляются. Дожим между прогонами
 * намеренно не делается: следующий backup пакует новый архив.
 */
final class S3MultipartUploader
{
    const MIN_PART_BYTES = 5242880;
    const UNSIGNED = 'UNSIGNED-PAYLOAD';

    /** @var S3Client */
    private $client;

    /** @var int */
    private $partBytes;

    /** @var int */
    private $retries;

    public function __construct(S3Client $client, $partBytes, $retries)
    {
        $this->client = $client;
        $this->partBytes = max(self::MIN_PART_BYTES, (int) $partBytes);
        $this->retries = max(0, (int) $retries);
    }

    /**
     * @param array<string, string> $headers Уже собранные заголовки объекта (meta, storage-class).
     */
    public function upload($key, $path, array $headers, callable $checkpoint = null, ProgressInterface $progress = null)
    {
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('Не удалось определить размер файла: ' . $path);
        }
        $size = (int) $size;
        if ($size <= 0) {
            throw new RuntimeException('Пустой файл нельзя выгрузить multipart: ' . $path);
        }

        $uploadId = null;
        try {
            $uploadId = $this->client->createMultipartUpload($key, $headers);
            $parts = [];
            $offset = 0;
            $partNumber = 1;

            while ($offset < $size) {
                if ($checkpoint) {
                    $checkpoint();
                }
                $length = (int) min($this->partBytes, $size - $offset);
                $etag = $this->uploadPartWithRetry($key, $uploadId, $partNumber, $path, $offset, $length, $checkpoint, $progress);
                $parts[] = ['part_number' => $partNumber, 'etag' => $etag];
                $offset += $length;
                $partNumber++;
            }

            $this->client->completeMultipartUpload($key, $uploadId, $parts);
            $uploadId = null;
        } catch (\Throwable $e) {
            if ($uploadId !== null) {
                try {
                    $this->client->abortMultipartUpload($key, $uploadId);
                } catch (\Throwable $ignored) {
                    // Обрыв выгрузки важнее: мусор в бакете подчистит lifecycle.
                }
            }
            throw $e;
        }
    }

    private function uploadPartWithRetry(
        $key,
        $uploadId,
        $partNumber,
        $path,
        $offset,
        $length,
        callable $checkpoint = null,
        ProgressInterface $progress = null
    ) {
        $attempt = 0;
        $last = null;

        while ($attempt <= $this->retries) {
            try {
                return $this->client->uploadPart(
                    $key,
                    $uploadId,
                    $partNumber,
                    $path,
                    $offset,
                    $length,
                    $checkpoint,
                    $progress
                );
            } catch (RuntimeException $e) {
                $last = $e;
                if ($attempt >= $this->retries || !$this->isRetryable($e)) {
                    throw $e;
                }
                usleep((int) (250000 * (2 ** $attempt)));
                $attempt++;
            }
        }

        throw $last ?: new RuntimeException('Не удалось выгрузить часть ' . $partNumber);
    }

    private function isRetryable(RuntimeException $e)
    {
        $message = $e->getMessage();
        if (strpos($message, 'не выполнен:') !== false) {
            return true;
        }

        return (bool) preg_match('/HTTP (408|429|500|502|503|504)\b/', $message);
    }
}
