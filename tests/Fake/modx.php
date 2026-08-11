<?php

/**
 * Фейковое ядро MODX для тестов.
 *
 * Настоящего `modX` в репозитории пакета нет, а адаптеры платформы без него не
 * загружаются. Здесь ровно то, чего они касаются, плюс управляемый обрыв
 * соединения: `$alive = false` — сервер «ушёл», как при wait_timeout.
 */

if (!defined('MODX_CORE_PATH')) {
    define('MODX_CORE_PATH', '/tmp/modx-core/');
}

if (!class_exists('modX')) {
    class modX
    {
        const LOG_LEVEL_DEBUG = 1;
        const LOG_LEVEL_INFO = 2;
        const LOG_LEVEL_WARN = 3;
        const LOG_LEVEL_ERROR = 4;

        public $mxlogger;
        public $service;
        public $logs = [];

        /** Отвечает ли сервер базы. */
        public $alive = true;
        /** Бросать ли исключение вместо возврата false, как это делает PDO. */
        public $throwWhenDead = false;
        /** Сколько раз соединение поднималось заново. */
        public $connects = 0;
        /** Все ушедшие в базу запросы, включая пинги. */
        public $queries = [];
        /** Сохранённые объекты xPDO. */
        public $saved = [];

        public $pdo;
        public $connection;

        public function getOption($key, $options = null, $default = null) { return $default; }
        public function addPackage($package, $path) { return true; }
        public function getService($name, $class, $path) { return $this->service; }
        public function log($level, $message) { $this->logs[] = compact('level', 'message'); }
        public function getTableName($class) { return '`' . strtolower($class) . '`'; }

        public function query($sql)
        {
            $this->queries[] = $sql;
            return $this->alive ? new modXFakeStatement($this) : $this->fail();
        }

        public function prepare($sql)
        {
            $this->queries[] = $sql;
            return $this->alive ? new modXFakeStatement($this) : $this->fail();
        }

        public function exec($sql)
        {
            $this->queries[] = $sql;
            return $this->alive ? 1 : $this->fail();
        }

        public function newObject($class)
        {
            return new modXFakeObject($this);
        }

        public function connect($driverOptions = [], array $options = [])
        {
            $this->connects++;
            $this->alive = true;

            return true;
        }

        private function fail()
        {
            if ($this->throwWhenDead) {
                throw new PDOException('MySQL server has gone away');
            }

            return false;
        }
    }

    class modXFakeStatement
    {
        public $rows = [];

        private $modx;

        public function __construct(modX $modx) { $this->modx = $modx; }

        public function execute($values = []) { return $this->modx->alive; }
        public function fetch($mode = null) { return array_shift($this->rows); }
        public function fetchAll($mode = null) { $rows = $this->rows; $this->rows = []; return $rows; }
        public function fetchColumn($column = 0) { $row = array_shift($this->rows); return $row === null ? false : reset($row); }
        public function rowCount() { return 1; }
        public function closeCursor() { return true; }
    }

    class modXFakeObject
    {
        private $modx;
        private $data = [];

        public function __construct(modX $modx) { $this->modx = $modx; }

        public function fromArray(array $data, $prefix = '', $setPrimary = false, $rawValues = false)
        {
            $this->data = $data + $this->data;
        }

        public function save()
        {
            if (!$this->modx->alive) {
                return false;
            }
            $this->data['id'] = count($this->modx->saved) + 1;
            $this->modx->saved[] = $this->data;

            return true;
        }

        public function get($key) { return isset($this->data[$key]) ? $this->data[$key] : null; }
    }
}
