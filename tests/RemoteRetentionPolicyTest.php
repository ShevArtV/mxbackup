<?php

namespace MxBackup\Tests;

use MxBackup\Core\Storage\RemoteRetentionPolicy;
use MxBackup\Tests\Fake\FakeRemoteStorage;
use PHPUnit\Framework\TestCase;

/**
 * Ротация в удалённом хранилище.
 *
 * Здесь та же логика, что у локальной, и та же цена ошибки: слишком жадная
 * ротация оставляет сайт без копий, слишком ленивая — платит за хранение.
 */
final class RemoteRetentionPolicyTest extends TestCase
{
    private const DAY = 86400;

    private function storage(array $ages, $now)
    {
        $storage = new FakeRemoteStorage();
        foreach ($ages as $index => $days) {
            $storage->seed('mxbackup-prod-' . $index . '.zip', $now - ($days * self::DAY));
        }

        return $storage;
    }

    public function testKeepsNewestByCount()
    {
        $now = 1770000000;
        $storage = $this->storage([0, 1, 2, 3, 4], $now);

        $result = (new RemoteRetentionPolicy())->cleanup($storage, 0, 2, $now);

        $this->assertCount(3, $result['deleted']);
        $this->assertSame(
            ['mxbackup-prod-0.zip', 'mxbackup-prod-1.zip'],
            array_column($storage->listArchives(), 'name')
        );
    }

    public function testDeletesByAge()
    {
        $now = 1770000000;
        $storage = $this->storage([1, 40, 50], $now);

        $result = (new RemoteRetentionPolicy())->cleanup($storage, 30, 0, $now);

        $this->assertSame(['mxbackup-prod-1.zip', 'mxbackup-prod-2.zip'], $result['deleted']);
    }

    /**
     * Ключевое свойство: остановившийся cron не должен приводить к тому, что
     * ротация вычистит вообще всё. Свежие `count` копий защищены и по возрасту.
     */
    public function testFreshCopiesSurviveAgeRule()
    {
        $now = 1770000000;
        $storage = $this->storage([100, 200, 300], $now);

        $result = (new RemoteRetentionPolicy())->cleanup($storage, 30, 2, $now);

        $this->assertSame(['mxbackup-prod-2.zip'], $result['deleted']);
        $this->assertCount(2, $storage->listArchives());
    }

    public function testNothingIsDeletedWithoutRules()
    {
        $now = 1770000000;
        $storage = $this->storage([1, 100], $now);

        $result = (new RemoteRetentionPolicy())->cleanup($storage, 0, 0, $now);

        $this->assertSame([], $result['deleted']);
        $this->assertCount(2, $storage->listArchives());
    }

    /**
     * Неудача удаления — не повод рушить прогон: архив уже создан и выгружен,
     * а неубранный старый файл это счёт за хранение, а не потеря данных.
     */
    public function testDeleteFailureIsReportedNotThrown()
    {
        $now = 1770000000;
        $storage = $this->storage([0, 10, 20], $now);
        $storage->undeletable = ['mxbackup-prod-2.zip'];

        $result = (new RemoteRetentionPolicy())->cleanup($storage, 0, 1, $now);

        $this->assertSame(['mxbackup-prod-1.zip'], $result['deleted']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('mxbackup-prod-2.zip', $result['errors'][0]);
    }
}
