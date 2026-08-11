<?php

namespace MxBackup\Platform\Modx2;

use RuntimeException;

/**
 * Соединение MODX с базой, пережившее простой.
 *
 * Backup и restore подолгу не трогают базу: упаковка архива, выгрузка в S3,
 * распаковка и раскладка файлов идут минутами и часами. MySQL закрывает
 * простаивающее соединение по `wait_timeout` (на shared-хостинге это нередко
 * 60–120 секунд), и следующий же запрос падает с «MySQL server has gone away»
 * (SQLSTATE HY000, код 2006).
 *
 * Штатного переподключения в xPDO 2 нет: `xPDOConnection::connect()` создаёт
 * PDO только когда `$pdo === null`, поэтому соединение поднимается заново
 * обнулением дескриптора и повторным `xPDO::connect()`.
 *
 * ⚠️ Прецедент 11.08.2026 (sleepandglow): восстановление падало на записи
 * страховочной копии в историю — `INSERT INTO modx_mxbackup_run` уходил в уже
 * закрытое соединение сразу после распаковки архива, и восстановление
 * отменялось с «Не удалось создать запись в истории backup».
 */
final class Connection
{
    /**
     * Как часто пинговать простаивающее соединение.
     *
     * Меньше самого короткого разумного `wait_timeout`: пинг стоит один
     * `SELECT 1`, а его пропуск — упавший прогон.
     */
    const KEEPALIVE_INTERVAL = 30.0;

    private $modx;

    /** Когда база в последний раз точно отвечала. */
    private $lastTouch;

    /** Внутри consistent snapshot переподключаться нельзя. */
    private $frozen = false;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
        $this->lastTouch = microtime(true);
    }

    public function modx()
    {
        return $this->modx;
    }

    /**
     * Запретить переподключение на время consistent snapshot.
     *
     * ⚠️ Транзакция живёт в соединении и умирает вместе с ним. Тихо
     * переподключиться посреди дампа значит дособрать копию из другого
     * состояния базы — внешне успешный архив с рассогласованными таблицами.
     * Поэтому в замороженном состоянии обрыв — это ошибка прогона.
     */
    public function freeze($frozen = true)
    {
        $this->frozen = (bool) $frozen;
    }

    /**
     * Поддержать простаивающее соединение живым.
     *
     * Зовётся из тика долгих стадий, поэтому дёшева: реальный пинг уходит не
     * чаще KEEPALIVE_INTERVAL.
     */
    public function keepAlive()
    {
        $now = microtime(true);
        if ($now - $this->lastTouch < self::KEEPALIVE_INTERVAL) {
            return;
        }
        $this->lastTouch = $now;
        if ($this->ping()) {
            return;
        }
        if ($this->frozen) {
            throw new RuntimeException(
                'Соединение с базой потеряно во время consistent snapshot; дамп прерван,'
                . ' чтобы не собрать копию из разных состояний базы.'
            );
        }
        // Неудача не валит прогон: база могла быть недоступна секунду, а
        // следующая операция всё равно попробует подняться через run().
        $this->reconnect();
    }

    /**
     * Выполнить работу с базой, пережив разрыв соединения.
     *
     * Признак разрыва не угадывается по коду ошибки: после неудачи соединение
     * проверяется пингом. Пинг прошёл — ошибка настоящая и возвращается как
     * есть; не прошёл — соединение поднимается заново и работа повторяется
     * ровно один раз.
     *
     * @param callable $work Операция с базой.
     * @param callable|null $failed Как понять, что результат — неудача; по умолчанию строгое `false`.
     * @return mixed Результат работы.
     */
    public function run(callable $work, callable $failed = null)
    {
        $this->lastTouch = microtime(true);
        try {
            $result = call_user_func($work);
        } catch (\Throwable $e) {
            if (!$this->recover()) {
                throw $e;
            }
            return call_user_func($work);
        }

        $bad = $failed ? (bool) call_user_func($failed, $result) : ($result === false);
        if ($bad && $this->recover()) {
            return call_user_func($work);
        }

        return $result;
    }

    /**
     * Соединение действительно потеряно и поднято заново.
     *
     * @return bool Повторять ли работу.
     */
    private function recover()
    {
        if ($this->frozen || $this->ping()) {
            return false;
        }
        if (!$this->reconnect()) {
            return false;
        }
        $this->lastTouch = microtime(true);

        return true;
    }

    private function ping()
    {
        try {
            $statement = @$this->modx->query('SELECT 1');
        } catch (\Throwable $e) {
            return false;
        }
        if (!$statement) {
            return false;
        }
        if (is_object($statement) && method_exists($statement, 'closeCursor')) {
            @$statement->closeCursor();
        }

        return true;
    }

    private function reconnect()
    {
        try {
            // `xPDO::$pdo` — ссылка на `xPDOConnection::$pdo`; обнуляются оба,
            // потому что ссылка могла и не сложиться (соединение не поднималось).
            if (isset($this->modx->connection) && is_object($this->modx->connection)) {
                $this->modx->connection->pdo = null;
            }
            $this->modx->pdo = null;
            $mutable = defined('xPDO::OPT_CONN_MUTABLE')
                ? constant('xPDO::OPT_CONN_MUTABLE')
                : 'connection_mutable';
            $connected = (bool) $this->modx->connect([], [$mutable => true]);
        } catch (\Throwable $e) {
            $connected = false;
        }

        $this->modx->log(
            $connected ? \modX::LOG_LEVEL_WARN : \modX::LOG_LEVEL_ERROR,
            $connected
                ? '[mxbackup] Соединение с базой было потеряно и восстановлено.'
                : '[mxbackup] Соединение с базой потеряно, переподключиться не удалось.'
        );

        return $connected;
    }
}
