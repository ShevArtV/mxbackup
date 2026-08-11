<?php

namespace MxBackup\Core\Progress;

/**
 * Прогресс, которого никто не ждёт: запуск из manager, cron без --progress,
 * тесты. Значение по умолчанию везде, где прогресс необязателен.
 */
final class NullProgress implements ProgressInterface
{
    public function plan(array $stages)
    {
    }

    public function stage($label, $total = null)
    {
    }

    public function total($total)
    {
    }

    public function advance($done = 1, $label = null)
    {
    }

    public function note($message)
    {
    }

    public function finish($summary = null)
    {
    }

    public function nested()
    {
        return $this;
    }
}
