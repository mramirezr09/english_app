<?php

declare(strict_types=1);

/**
 * Application bootstrap: project paths, PSR-4 style autoloader and helpers.
 */

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $length = strlen($prefix);
    if (strncmp($class, $prefix, $length) !== 0) {
        return;
    }

    $relative = substr($class, $length);
    $file = BASE_PATH . '/backend/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once BASE_PATH . '/backend/helpers/utf8.php';
