#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "mxBackup CLI доступен только из командной строки.\n");
    exit(2);
}

$root = dirname(__DIR__, 4);
foreach ($argv as $argument) {
    if (strpos($argument, '--modx-root=') === 0) {
        $root = rtrim(substr($argument, strlen('--modx-root=')), '/\\');
        break;
    }
}
if (!is_file($root . '/config.core.php')) {
    fwrite(STDERR, "Не найден config.core.php. Передайте --modx-root=/path/to/site\n");
    exit(2);
}

define('MODX_API_MODE', true);
require_once $root . '/config.core.php';
require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

$modx = new modX();
$modx->initialize('mgr');
$modx->getService('error', 'error.modError');

require_once dirname(__DIR__) . '/autoload.php';

$application = new \MxBackup\Cli\Application($modx);
exit($application->run($argv));
