#!/usr/bin/env php
<?php

/**
 * Registers and installs the package archive built into core/packages.
 * Run from CLI on a MODX 2 installation after build.package.php.
 */

set_time_limit(0);

$root = dirname(__DIR__, 2) . '/';
require_once $root . 'config.core.php';
require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
require_once MODX_CONNECTORS_PATH . 'index.php';

/** @var modX $modx */
$modx->initialize('mgr');
$modx->setLogTarget(XPDO_CLI_MODE ? 'ECHO' : 'HTML');
$modx->setLogLevel(modX::LOG_LEVEL_INFO);
$modx->user = $modx->getObject('modUser', 1);

$signature = 'mxbackup-1.1.0-beta';
$scan = $modx->runProcessor('workspace/packages/scanlocal');
if (!$scan || $scan->isError()) {
    fwrite(STDERR, "Не удалось просканировать core/packages: " . ($scan ? $scan->getMessage() : 'нет ответа') . PHP_EOL);
    exit(1);
}

$install = $modx->runProcessor('workspace/packages/install', ['signature' => $signature]);
if (!$install || $install->isError()) {
    fwrite(STDERR, "Не удалось установить {$signature}: " . ($install ? $install->getMessage() : 'нет ответа') . PHP_EOL);
    exit(1);
}

echo "Установлен {$signature}." . PHP_EOL;
