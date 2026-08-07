<?php

define('COMPONENT_BUILD', false);
$root = dirname(__DIR__, 4) . '/';
$builderRoot = $root . 'modxbuilder/';
$modxRoot = $root;

$buildConfig = [
    'real_package_name' => 'mxBackup',
    'package_name' => 'mxbackup',
    'package_version' => '1.4.0',
    'package_release' => 'rc',
    'package_table_prefix' => 'mxbackup_',
    'package_class_prefix' => 'mxBackup',
    'regenerate_schema' => true,
    'regenerate_classes' => true,
    'regenerate_maps' => true,
    'modx_root' => $modxRoot,
    'builder_root' => $builderRoot,
    'tools_root' => $builderRoot . 'tools/',
];

$componentRoot = $builderRoot . $buildConfig['package_name'] . '/';
$buildConfig = array_merge($buildConfig, [
    'root' => $root,
    'build' => $componentRoot . 'build/',
    'resolvers' => $componentRoot . 'build/resolvers/',
    'data' => $componentRoot . 'build/data/',
    'source_core' => $modxRoot . 'core/components/mxbackup/',
    'source_lexicon' => $modxRoot . 'core/components/mxbackup/lexicon/',
    'source_assets' => $modxRoot . 'assets/components/mxbackup/',
    'source_docs' => $modxRoot . 'core/components/mxbackup/docs/',
    'package_dir' => $root . 'core/components/mxbackup',
    'model_dir' => $root . 'core/components/mxbackup/model',
    'class_dir' => $root . 'core/components/mxbackup/model/mxbackup',
    'schema_dir' => $root . 'core/components/mxbackup/model/schema',
    'mysql_class_dir' => $root . 'core/components/mxbackup/model/mxbackup/mysql',
    'xml_schema_file' => $root . 'core/components/mxbackup/model/schema/mxbackup.mysql.schema.xml',
    'new_xml_schema_file' => $root . 'core/components/mxbackup/model/schema/mxbackup.mysql.schema.new.xml',
]);

define('MODX_CORE_PATH', $modxRoot . 'core/');
define('MODX_BASE_PATH', $modxRoot);
define('MODX_BASE_URL', '/');
unset($root, $builderRoot, $modxRoot, $componentRoot);
return $buildConfig;
