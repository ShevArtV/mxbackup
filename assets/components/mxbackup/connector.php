<?php

require_once dirname(__FILE__, 4) . '/config.core.php';
require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
require_once MODX_CONNECTORS_PATH . 'index.php';

/** @var modX $modx */
$modx->lexicon->load('mxbackup:default');
$corePath = $modx->getOption('mxbackup.core_path', null, MODX_CORE_PATH . 'components/mxbackup/');
$modx->addPackage('mxbackup', $corePath . 'model/');
require_once $corePath . 'autoload.php';

$modx->request->handleRequest([
    'processors_path' => $corePath . 'processors/',
    'location' => '',
]);
