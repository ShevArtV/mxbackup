<?php

/**
 * Small package-local PSR-4 loader. mxBackup has no runtime Composer
 * dependencies, so a generated vendor tree does not need to travel in the
 * transport package.
 */
spl_autoload_register(static function ($class) {
    $prefix = 'MxBackup\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
