<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

use App\Core\Router;

// When the PHP built-in server uses this file as its router script, let it
// serve existing static assets (CSS, JS, videos) directly.
if (PHP_SAPI === 'cli-server') {
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

$router = new Router();
$routes = require dirname(__DIR__) . '/backend/routes.php';
$routes($router);
$router->dispatch();
