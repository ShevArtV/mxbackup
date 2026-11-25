<?php

// Константы MODX для статического анализа: значения не важны, важно наличие.
foreach ([
    'MODX_API_MODE' => true,
    'MODX_CONFIG_KEY' => 'config',
    'MODX_BASE_PATH' => '/modx/',
    'MODX_CORE_PATH' => '/modx/core/',
    'MODX_ASSETS_PATH' => '/modx/assets/',
    'MODX_MANAGER_PATH' => '/modx/manager/',
    'MODX_CONNECTORS_PATH' => '/modx/connectors/',
    'MODX_BASE_URL' => '/',
    'MODX_ASSETS_URL' => '/assets/',
    'MODX_MANAGER_URL' => '/manager/',
    'MODX_CONNECTORS_URL' => '/connectors/',
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
