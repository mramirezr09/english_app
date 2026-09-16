<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Read-only access to the current HTTP request.
 */
final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = rawurldecode((string) ($uri ?: '/'));
        return $uri === '' ? '/' : $uri;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    /**
     * Decoded JSON body (empty array when the body is not valid JSON).
     */
    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * A value coming from the JSON body, falling back to form data.
     */
    public static function input(string $key, mixed $default = null): mixed
    {
        $json = self::json();
        return $json[$key] ?? $_POST[$key] ?? $default;
    }
}
