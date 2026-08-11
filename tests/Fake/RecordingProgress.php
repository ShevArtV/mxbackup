<?php

namespace MxBackup\Tests\Fake;

use MxBackup\Core\Progress\ProgressInterface;

/**
 * Прогресс, который ничего не печатает, а запоминает вызовы.
 *
 * Нужен, чтобы проверять порядок стадий раннеров, не разбирая вывод терминала:
 * форматирование — забота ConsoleProgress, а последовательность — раннера.
 */
final class RecordingProgress implements ProgressInterface
{
    /** @var array<int, string> Стадии в порядке объявления. */
    public $stages = [];

    /** @var array<int, string> План, объявленный последним вызовом plan(). */
    public $planned = [];

    /** @var array<string, int> Сумма advance() по стадиям. */
    public $advanced = [];

    /** @var array<int, string> */
    public $notes = [];

    /** @var bool */
    public $finished = false;

    /** @var array<int, self> Вложенные прогрессы — по одному на вложенный прогон. */
    public $children = [];

    /** @var string|null */
    private $current = null;

    public function plan(array $stages)
    {
        $this->planned = array_values(array_map('strval', $stages));
    }

    public function stage($label, $total = null)
    {
        $this->current = (string) $label;
        $this->stages[] = $this->current;
        if (!isset($this->advanced[$this->current])) {
            $this->advanced[$this->current] = 0;
        }
    }

    public function total($total)
    {
    }

    public function advance($done = 1, $label = null)
    {
        if ($this->current === null) {
            return;
        }
        $this->advanced[$this->current] += (int) $done;
    }

    public function note($message)
    {
        $this->notes[] = (string) $message;
    }

    public function finish($summary = null)
    {
        $this->finished = true;
        $this->current = null;
    }

    public function nested()
    {
        $child = new self();
        $this->children[] = $child;

        return $child;
    }
}
