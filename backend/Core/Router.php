<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Minimal front-controller router supporting {param} placeholders.
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, handler:callable|array}> */
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function head(string $pattern, callable|array $handler): void
    {
        $this->add('HEAD', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable|array $handler): void
    {
        $this->routes[] = [
            'method'  => $method,
            'regex'   => $this->compile($pattern),
            'handler' => $handler,
        ];
    }

    private function compile(string $pattern): string
    {
        $trimmed = trim($pattern, '/');
        if ($trimmed === '') {
            return '#^/?$#';
        }

        $parts = explode('/', $trimmed);
        $regex = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{(\w+)\}$/', $part, $match)) {
                $regex[] = '(?P<' . $match[1] . '>[^/]+)';
            } elseif (preg_match('/^\{(\w+)\.\.\.\}$/', $part, $match)) {
                // Catch-all: matches the rest of the path including slashes.
                $regex[] = '(?P<' . $match[1] . '>.+)';
            } else {
                $regex[] = preg_quote($part, '#');
            }
        }

        return '#^/' . implode('/', $regex) . '/?$#';
    }

    public function dispatch(): void
    {
        $method = Request::method();
        $path = Request::path();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            try {
                $handler = $route['handler'];
                if (is_array($handler)) {
                    [$class, $action] = $handler;
                    (new $class())->{$action}($params);
                } else {
                    $handler($params);
                }
            } catch (Throwable $e) {
                error_log('Router error: ' . $e->getMessage());
                Response::html('Error interno: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 500);
            }
            return;
        }

        Response::notFound();
    }
}
