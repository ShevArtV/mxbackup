<?php

namespace MxBackup\Core\Masking;

use InvalidArgumentException;

final class Rule
{
    private $table;
    private $column;
    private $action;
    private $value;
    private $priority;
    private $jsonPath;
    private $when;

    /**
     * @param array $when Условие по соседним колонкам той же строки:
     *                    `['key' => ['*secret*', '*token*']]`. Пустой массив —
     *                    правило применяется к каждой строке, как раньше.
     */
    public function __construct($table, $column, $action, $value = null, $priority = 0, $jsonPath = null, array $when = [])
    {
        if (!in_array($action, ['mask', 'hide', 'truncate', 'hash', 'replace'], true)) {
            throw new InvalidArgumentException('Неизвестное действие masking: ' . $action);
        }
        $this->table = (string)$table;
        $this->column = (string)$column;
        $this->action = $action;
        $this->value = $value;
        $this->priority = (int)$priority;
        $this->jsonPath = $jsonPath === null ? null : (string)$jsonPath;
        $this->when = self::normalizeWhen($when);
    }

    public function matches($table, $column = null)
    {
        if (!fnmatch($this->table, $table)) {
            return false;
        }
        return $column === null || $this->column === '*' || fnmatch($this->column, $column);
    }

    /**
     * Подходит ли строка под условие правила.
     *
     * Нужно там, где значение и его имя лежат в разных колонках одной строки:
     * в `modx_system_settings` секрет платёжного шлюза ничем не отличается от
     * обычной настройки, пока не посмотришь на соседнюю колонку `key`. Правило
     * по колонке `value` без условия вычистило бы настройки целиком.
     *
     * Условия по разным колонкам соединяются «И», шаблоны внутри одной
     * колонки — «ИЛИ». Сравнение регистронезависимое: имена настроек пишут
     * по-разному, а `fnmatch` регистр учитывает.
     *
     * Шаблон с `!` в начале — исключение: он не добавляет строки, а убирает
     * уже подошедшие. Без него узкое правило не написать: `*password*` ловит
     * не только пароль SMTP, но и `password_min_length`, а `*token*` — ещё и
     * `token_ttl`; обнулённая политика паролей в копии никому не нужна.
     */
    public function appliesToRow(array $row)
    {
        foreach ($this->when as $column => $patterns) {
            if (!array_key_exists($column, $row)) {
                return false;
            }
            $value = strtolower((string)$row[$column]);
            $matched = null;
            foreach ($patterns as $pattern) {
                if ($pattern[0] === '!') {
                    if (fnmatch(substr($pattern, 1), $value)) {
                        return false;
                    }
                    continue;
                }
                if ($matched === null) {
                    $matched = false;
                }
                if (fnmatch($pattern, $value)) {
                    $matched = true;
                }
            }
            // null — позитивных шаблонов нет вовсе, условие состоит из одних
            // исключений: подходит всё, кроме перечисленного.
            if ($matched === false) {
                return false;
            }
        }
        return true;
    }

    public function hasCondition()
    {
        return $this->when !== [];
    }

    public function getTable() { return $this->table; }
    public function getColumn() { return $this->column; }
    public function getAction() { return $this->action; }
    public function getValue() { return $this->value; }
    public function getPriority() { return $this->priority; }
    public function getJsonPath() { return $this->jsonPath; }
    public function getWhen() { return $this->when; }

    /**
     * Разбирает значение формы (`when` приходит JSON-строкой) или массива из
     * файлового профиля и возвращает единый формат для хранения.
     */
    public static function parseWhen($value)
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return [];
            }
            $value = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('Условие masking содержит некорректный JSON.');
            }
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Условие masking должно быть объектом.');
        }
        return self::normalizeWhen($value);
    }

    public static function normalizeWhen(array $when)
    {
        $normalized = [];
        foreach ($when as $column => $patterns) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Имя колонки в условии masking должно быть строкой.');
            }
            $column = trim((string)$column);
            if ($column === '') {
                throw new InvalidArgumentException('В условии masking не задано имя колонки.');
            }
            $list = [];
            foreach (is_array($patterns) ? $patterns : [$patterns] as $pattern) {
                if (!is_scalar($pattern)) {
                    throw new InvalidArgumentException('Шаблон условия masking должен быть строкой.');
                }
                $pattern = strtolower(trim((string)$pattern));
                if ($pattern !== '' && $pattern !== '!') {
                    $list[] = $pattern;
                }
            }
            if (!$list) {
                throw new InvalidArgumentException('Условие masking по колонке ' . $column . ' не содержит ни одного шаблона.');
            }
            $normalized[$column] = array_values(array_unique($list));
        }
        return $normalized;
    }
}
