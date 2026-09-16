<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base class for application controllers.
 */
abstract class Controller
{
    protected function view(string $template, array $data = [], ?string $layout = 'layouts/main'): void
    {
        View::render($template, $data, $layout);
        exit;
    }

    protected function json(array $data, int $status = 200): void
    {
        Response::json($data, $status);
    }

    protected function redirect(string $location): void
    {
        Response::redirect($location);
    }
}
