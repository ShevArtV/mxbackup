<?php

namespace MxBackup\Tests;

use MxBackup\Platform\Modx2\Connection;
use MxBackup\Platform\Modx2\RunRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConnectionTest extends TestCase
{
    public function testRetriesWorkAfterServerWentAway()
    {
        $modx = new \modX();
        $connection = new Connection($modx);
        $calls = 0;

        $result = $connection->run(static function () use ($modx, &$calls) {
            $calls++;
            // Первый заход застаёт закрытое соединение, как после wait_timeout.
            if ($calls === 1) {
                $modx->alive = false;
                return false;
            }
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(2, $calls);
        self::assertSame(1, $modx->connects);
    }

    public function testRetriesAfterExceptionFromDeadConnection()
    {
        $modx = new \modX();
        $modx->throwWhenDead = true;
        $connection = new Connection($modx);
        $calls = 0;

        $result = $connection->run(static function () use ($modx, &$calls) {
            $calls++;
            if ($calls === 1) {
                $modx->alive = false;
                throw new \PDOException('MySQL server has gone away');
            }
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(1, $modx->connects);
    }

    public function testKeepsRealFailureWhenConnectionIsAlive()
    {
        $modx = new \modX();
        $connection = new Connection($modx);
        $calls = 0;

        $result = $connection->run(static function () use (&$calls) {
            $calls++;
            return false;
        });

        self::assertFalse($result);
        self::assertSame(1, $calls, 'Живое соединение повтора не заслуживает: ошибка настоящая.');
        self::assertSame(0, $modx->connects);
    }

    public function testKeepAliveRevivesIdleConnection()
    {
        $modx = new \modX();
        $connection = new Connection($modx);
        $modx->alive = false;

        // Первый вызов молчит: с момента создания соединения простоя ещё нет.
        $connection->keepAlive();
        self::assertSame(0, $modx->connects);

        $this->rewindIdleClock($connection);
        $connection->keepAlive();

        self::assertSame(1, $modx->connects);
        self::assertContains('SELECT 1', $modx->queries);
    }

    public function testKeepAliveFailsLoudlyInsideConsistentSnapshot()
    {
        $modx = new \modX();
        $connection = new Connection($modx);
        $connection->freeze(true);
        $modx->alive = false;
        $this->rewindIdleClock($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('consistent snapshot');

        try {
            $connection->keepAlive();
        } finally {
            self::assertSame(0, $modx->connects, 'В снапшоте переподключение недопустимо: транзакция уже потеряна.');
        }
    }

    public function testFrozenConnectionDoesNotRetry()
    {
        $modx = new \modX();
        $connection = new Connection($modx);
        $connection->freeze(true);
        $calls = 0;

        $result = $connection->run(static function () use ($modx, &$calls) {
            $calls++;
            $modx->alive = false;
            return false;
        });

        self::assertFalse($result);
        self::assertSame(1, $calls);
        self::assertSame(0, $modx->connects);
    }

    public function testRunRepositoryWritesHistoryAfterServerWentAway()
    {
        $modx = new \modX();
        $modx->alive = false;
        $runs = new RunRepository(new Connection($modx));

        $id = $runs->start([
            'profile' => 'pre-restore',
            'mode' => 'prod',
            'status' => 'running',
        ]);

        self::assertSame(1, $id, 'Запись истории должна пережить закрытое сервером соединение.');
        self::assertSame(1, $modx->connects);
    }

    /**
     * Отмотать внутренний таймер простоя, чтобы не ждать KEEPALIVE_INTERVAL секунд.
     */
    private function rewindIdleClock(Connection $connection)
    {
        $property = (new \ReflectionClass(Connection::class))->getProperty('lastTouch');
        $property->setAccessible(true);
        $property->setValue($connection, microtime(true) - Connection::KEEPALIVE_INTERVAL - 1);
    }
}
