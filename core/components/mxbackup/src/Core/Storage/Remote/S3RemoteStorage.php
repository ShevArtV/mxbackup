<?php

namespace MxBackup\Core\Storage\Remote;

use MxBackup\Core\Contract\RemoteStorageInterface;

/**
 * Хранилище архивов в S3 или совместимом сервисе.
 */
final class S3RemoteStorage implements RemoteStorageInterface
{
    /** @var S3Client */
    private $client;

    /** @var string Префикс ключа: пустой либо с завершающим слэшем. */
    private $prefix;

    /** @var string */
    private $bucket;

    /** @var string */
    private $storageClass;

    public function __construct(array $config, S3Client $client = null)
    {
        $this->bucket = isset($config['bucket']) ? (string) $config['bucket'] : '';
        $prefix = isset($config['prefix']) ? trim((string) $config['prefix'], '/') : '';
        $this->prefix = $prefix === '' ? '' : $prefix . '/';
        $this->storageClass = isset($config['storage_class']) ? (string) $config['storage_class'] : '';
        $this->client = $client ?: new S3Client($config);
    }

    public function describe()
    {
        return 's3://' . $this->bucket . '/' . $this->prefix;
    }

    public function upload($path, $name, array $metadata = [])
    {
        $key = $this->prefix . $name;
        $this->client->putFile($key, $path, $metadata, $this->storageClass);

        return 's3://' . $this->bucket . '/' . $key;
    }

    public function download($name, $target)
    {
        return $this->client->getObject($this->prefix . $name, $target);
    }

    public function listArchives()
    {
        $archives = [];
        foreach ($this->client->listObjects($this->prefix) as $object) {
            $name = substr($object['key'], strlen($this->prefix));
            // Чужие объекты под тем же префиксом ротация трогать не должна:
            // бакет может быть общим, а удаление необратимо.
            if ($name === '' || !preg_match('/^mxbackup-.+\.(?:zip|tar\.gz)$/', $name)) {
                continue;
            }
            $archives[] = ['name' => $name, 'size' => $object['size'], 'modified' => $object['modified']];
        }

        usort($archives, static function (array $a, array $b) {
            return $b['modified'] <=> $a['modified'];
        });

        return $archives;
    }

    public function delete($name)
    {
        $this->client->deleteObject($this->prefix . $name);
    }
}
