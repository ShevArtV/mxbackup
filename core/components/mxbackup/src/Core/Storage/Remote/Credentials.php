<?php

namespace MxBackup\Core\Storage\Remote;

/**
 * Учётные данные AWS: ключ, секрет и (для временных) токен сессии.
 */
final class Credentials
{
    /** @var string */
    private $key;

    /** @var string */
    private $secret;

    /** @var string */
    private $token;

    /** @var int|null Момент истечения для временных данных; null — бессрочные. */
    private $expiresAt;

    public function __construct($key, $secret, $token = '', $expiresAt = null)
    {
        $this->key = (string) $key;
        $this->secret = (string) $secret;
        $this->token = (string) $token;
        $this->expiresAt = $expiresAt === null ? null : (int) $expiresAt;
    }

    public function getKey()
    {
        return $this->key;
    }

    public function getSecret()
    {
        return $this->secret;
    }

    public function getToken()
    {
        return $this->token;
    }

    /**
     * Данные роли живут около часа. Запас в пять минут: копия большого сайта
     * пакуется десятками минут, и подписать запрос ключом, истёкшим за время
     * упаковки, — самый обидный способ потерять готовый архив.
     */
    public function isExpired($now = null)
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return ($now ?: time()) >= ($this->expiresAt - 300);
    }

    public function isEmpty()
    {
        return $this->key === '' || $this->secret === '';
    }
}
