<?php

namespace MxBackup\Tests;

use MxBackup\Cli\ConsoleProgress;
use PHPUnit\Framework\TestCase;

final class ConsoleProgressTest extends TestCase
{
    /**
     * Вне терминала перерисовка превратилась бы в кашу из управляющих символов —
     * там печатаются только начало стадии и её итог.
     */
    public function testPlainOutputHasNoCarriageReturns()
    {
        $stream = fopen('php://memory', 'w+b');
        $progress = new ConsoleProgress($stream, false);
        $progress->plan(['Сбор файлов', 'Дамп базы']);
        $progress->stage('Сбор файлов');
        $progress->advance(1234, 'core/index.php');
        $progress->finish();

        $output = $this->read($stream);
        self::assertStringNotContainsString("\r", $output);
        self::assertStringContainsString('[1/2] Сбор файлов…', $output);
        self::assertStringContainsString('[1/2] Сбор файлов — 1 234, ', $output);
    }

    public function testInteractiveOutputDrawsBarAndPercent()
    {
        $stream = fopen('php://memory', 'w+b');
        $progress = new ConsoleProgress($stream, true);
        $progress->plan(['Упаковка архива']);
        $progress->stage('Упаковка архива', 10);
        // Тик сразу после стадии съел бы троттлинг: строка перерисовывается не
        // чаще REDRAW_INTERVAL.
        usleep((int) (ConsoleProgress::REDRAW_INTERVAL * 1000000) + 20000);
        $progress->advance(5, 'site/index.php');
        $progress->finish();

        $output = $this->read($stream);
        self::assertStringContainsString("\r", $output);
        self::assertStringContainsString('[==========..........]', $output);
        self::assertStringContainsString('5/10', $output);
        self::assertStringContainsString('50%', $output);
    }

    /**
     * Стадия дампа тикает на каждой таблице, выгрузка — на каждом килобайте:
     * без троттлинга терминал получил бы тысячи перерисовок в секунду.
     */
    public function testRedrawIsThrottled()
    {
        $stream = fopen('php://memory', 'w+b');
        $progress = new ConsoleProgress($stream, true);
        $progress->stage('Выгрузка', 1000);
        for ($i = 0; $i < 500; $i++) {
            $progress->advance(1);
        }
        $progress->finish();

        // Первая отрисовка принудительная, дальше не чаще REDRAW_INTERVAL. Пятьсот
        // тиков укладываются в доли секунды, поэтому запас взят с большим полем.
        self::assertLessThan(50, substr_count($this->read($stream), "\r"));
    }

    /**
     * Вложенный прогон (страховочная копия внутри restore) печатается с отступом
     * и со своей нумерацией стадий.
     */
    public function testNestedProgressIsIndented()
    {
        $stream = fopen('php://memory', 'w+b');
        $progress = new ConsoleProgress($stream, false);
        $progress->plan(['Страховочная копия']);
        $progress->stage('Страховочная копия');
        $nested = $progress->nested();
        $nested->plan(['Сбор файлов']);
        $nested->stage('Сбор файлов');
        $nested->finish();

        self::assertStringContainsString('  [1/1] Сбор файлов…', $this->read($stream));
    }

    private function read($stream)
    {
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        return $output;
    }
}
