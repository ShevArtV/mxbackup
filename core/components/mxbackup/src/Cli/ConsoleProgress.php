<?php

namespace MxBackup\Cli;

use MxBackup\Core\Progress\ProgressInterface;

/**
 * Ход операции в терминале.
 *
 * Пишет в STDERR: в STDOUT команды отдают JSON, и его парсят скрипты — туда
 * ничего постороннего попасть не должно.
 *
 * В терминале строка стадии перерисовывается на месте через `\r`. Когда вывод
 * перенаправлен в файл или в письмо cron, перерисовка превратилась бы в кашу,
 * поэтому там печатаются только начало и итог каждой стадии — по ним видно и
 * тайминги, и место, на котором запуск оборвался.
 */
final class ConsoleProgress implements ProgressInterface
{
    /** Чаще этого перерисовывать строку незачем: глазу хватает, а вывод дешевле. */
    const REDRAW_INTERVAL = 0.1;

    private $stream;
    private $interactive;
    private $depth;
    private $width;

    private $stages = [];
    private $index = 0;
    private $label = null;
    private $total = null;
    private $done = 0;
    private $item = null;
    private $startedAt = 0.0;
    private $lastRedraw = 0.0;
    private $painted = 0;

    /**
     * @param resource|null $stream Куда писать; по умолчанию STDERR.
     * @param bool|null $interactive Терминал ли это; по умолчанию определяется сам.
     * @param int $depth Уровень вложенности (отступ и нумерация своя).
     */
    public function __construct($stream = null, $interactive = null, $depth = 0)
    {
        $this->stream = $stream !== null ? $stream : (defined('STDERR') ? STDERR : fopen('php://stderr', 'wb'));
        $this->interactive = $interactive === null ? $this->detectTty($this->stream) : (bool) $interactive;
        $this->depth = max(0, (int) $depth);
        $columns = (int) getenv('COLUMNS');
        $this->width = $columns > 40 ? $columns : 80;
    }

    public function plan(array $stages)
    {
        $this->stages = array_values(array_map('strval', $stages));
        $this->index = 0;
    }

    public function stage($label, $total = null)
    {
        $this->closeStage();
        $this->index++;
        $this->label = (string) $label;
        $this->total = $total === null ? null : max(0, (int) $total);
        $this->done = 0;
        $this->item = null;
        $this->startedAt = microtime(true);
        $this->lastRedraw = 0.0;
        if ($this->interactive) {
            $this->redraw(true);
        } else {
            $this->line($this->prefix() . $this->label . '…');
        }
    }

    public function total($total)
    {
        if ($this->label === null) {
            return;
        }
        $this->total = $total === null ? null : max(0, (int) $total);
        $this->redraw();
    }

    public function advance($done = 1, $label = null)
    {
        if ($this->label === null) {
            return;
        }
        $this->done += (int) $done;
        if ($label !== null) {
            $this->item = (string) $label;
        }
        $this->redraw();
    }

    public function note($message)
    {
        $this->erase();
        $this->line($this->indent() . (string) $message);
        if ($this->interactive && $this->label !== null) {
            $this->redraw(true);
        }
    }

    public function finish($summary = null)
    {
        $this->closeStage();
        if ($summary !== null && $summary !== '') {
            $this->line($this->indent() . (string) $summary);
        }
    }

    public function nested()
    {
        return new self($this->stream, $this->interactive, $this->depth + 1);
    }

    /**
     * Итог стадии остаётся в выводе строкой: сколько сделано и за сколько.
     */
    private function closeStage()
    {
        if ($this->label === null) {
            return;
        }
        $seconds = microtime(true) - $this->startedAt;
        $counter = $this->total !== null && $this->total > 0
            ? $this->number($this->done) . '/' . $this->number($this->total)
            : ($this->done > 0 ? $this->number($this->done) : '');
        $summary = $this->prefix() . $this->label;
        if ($counter !== '') {
            $summary .= ' — ' . $counter;
        }
        $summary .= ($counter !== '' ? ', ' : ' — ') . $this->duration($seconds);
        $this->erase();
        $this->line($summary);
        $this->label = null;
        $this->item = null;
        $this->total = null;
        $this->done = 0;
    }

    private function redraw($force = false)
    {
        if (!$this->interactive || $this->label === null) {
            return;
        }
        $now = microtime(true);
        if (!$force && $now - $this->lastRedraw < self::REDRAW_INTERVAL) {
            return;
        }
        $this->lastRedraw = $now;

        $text = $this->prefix() . $this->label;
        if ($this->total !== null && $this->total > 0) {
            $percent = (int) floor(min(1, $this->done / $this->total) * 100);
            $text .= ' ' . $this->bar($percent) . ' ' . $this->number($this->done) . '/' . $this->number($this->total)
                . ' ' . str_pad($percent . '%', 4, ' ', STR_PAD_LEFT);
        } elseif ($this->done > 0) {
            $text .= ' ' . $this->number($this->done);
        }
        if ($this->item !== null && $this->item !== '') {
            $text .= '  ' . $this->item;
        }

        $text = $this->truncate($text, $this->width - 1);
        // Хвост прошлой, более длинной строки затирается пробелами; курсор после
        // них возвращается в начало и строка печатается заново.
        $padding = max(0, $this->painted - $this->strlen($text));
        $this->write($padding > 0
            ? "\r" . $text . str_repeat(' ', $padding) . "\r" . $text
            : "\r" . $text);
        $this->painted = $this->strlen($text);
    }

    /**
     * Стереть перерисовываемую строку перед печатью обычной: иначе хвост
     * прогресса останется висеть в конце сообщения.
     */
    private function erase()
    {
        if (!$this->interactive || $this->painted === 0) {
            return;
        }
        $this->write("\r" . str_repeat(' ', $this->painted) . "\r");
        $this->painted = 0;
    }

    private function line($text)
    {
        $this->write($text . PHP_EOL);
        $this->painted = 0;
    }

    private function write($text)
    {
        if (is_resource($this->stream)) {
            fwrite($this->stream, $text);
        }
    }

    private function bar($percent)
    {
        $width = 20;
        $filled = (int) round($width * $percent / 100);
        return '[' . str_repeat('=', $filled) . str_repeat('.', $width - $filled) . ']';
    }

    private function prefix()
    {
        $count = count($this->stages);
        $position = $count && $this->index >= 1 && $this->index <= $count
            ? '[' . $this->index . '/' . $count . '] '
            : '';
        return $this->indent() . $position;
    }

    private function indent()
    {
        return str_repeat('  ', $this->depth);
    }

    private function number($value)
    {
        return number_format((int) $value, 0, '.', ' ');
    }

    private function duration($seconds)
    {
        if ($seconds < 60) {
            return number_format($seconds, 1, '.', '') . ' с';
        }
        $minutes = (int) floor($seconds / 60);
        if ($minutes < 60) {
            return $minutes . ' мин ' . (int) round($seconds - $minutes * 60) . ' с';
        }
        $hours = (int) floor($minutes / 60);
        return $hours . ' ч ' . ($minutes - $hours * 60) . ' мин';
    }

    private function truncate($text, $limit)
    {
        if ($limit < 8 || $this->strlen($text) <= $limit) {
            return $text;
        }
        return $this->substr($text, 0, $limit - 1) . '…';
    }

    private function strlen($text)
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private function substr($text, $start, $length)
    {
        return function_exists('mb_substr') ? mb_substr($text, $start, $length, 'UTF-8') : substr($text, $start, $length);
    }

    private function detectTty($stream)
    {
        if (!is_resource($stream)) {
            return false;
        }
        if (function_exists('stream_isatty')) {
            return @stream_isatty($stream);
        }
        return function_exists('posix_isatty') && @posix_isatty($stream);
    }
}
