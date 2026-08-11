<?php

namespace MxBackup\Core\Progress;

/**
 * Ход длительной операции — для того, кто её ждёт.
 *
 * Ядро не знает, куда пишется прогресс: в CLI это строка в терминале, в
 * тестах и в manager — {@see NullProgress}, который не делает ничего. Поэтому
 * здесь нет ни форматирования, ни ширины экрана: только «начали стадию»,
 * «продвинулись», «закончили».
 */
interface ProgressInterface
{
    /**
     * Объявить состав операции заранее, чтобы стадии можно было нумеровать.
     *
     * @param string[] $stages Метки стадий в порядке выполнения.
     * @return void
     */
    public function plan(array $stages);

    /**
     * Начать очередную стадию; предыдущая при этом считается завершённой.
     *
     * @param string $label Человекочитаемая метка.
     * @param int|null $total Сколько единиц предстоит, если известно заранее.
     * @return void
     */
    public function stage($label, $total = null);

    /**
     * Уточнить объём текущей стадии, когда он выяснился по ходу дела.
     *
     * @param int|null $total
     * @return void
     */
    public function total($total);

    /**
     * Продвинуться по текущей стадии.
     *
     * @param int $done Сколько единиц обработано с прошлого вызова.
     * @param string|null $label Что именно сейчас обрабатывается (имя таблицы, файл).
     * @return void
     */
    public function advance($done = 1, $label = null);

    /**
     * Разовое сообщение, не привязанное к счётчику.
     *
     * @param string $message
     * @return void
     */
    public function note($message);

    /**
     * Операция завершена: текущая стадия закрывается, печатается итог.
     *
     * @param string|null $summary
     * @return void
     */
    public function finish($summary = null);

    /**
     * Прогресс для вложенной операции.
     *
     * Восстановление внутри себя делает полноценный backup, и его стадии не
     * должны сбивать нумерацию родителя.
     *
     * @return ProgressInterface
     */
    public function nested();
}
