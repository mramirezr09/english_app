<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Renders PHP templates from frontend/views.
 */
final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/main'): void
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            echo $content;
            return;
        }

        $data['content'] = $content;
        echo self::partial($layout, $data);
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = Config::viewsDir() . '/' . str_replace('.', '/', $template) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Vista no encontrada: ' . $template);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
