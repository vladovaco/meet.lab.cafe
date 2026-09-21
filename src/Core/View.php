<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    /** @var list<string> skripty zaregistrované šablónou, vykreslia sa v layoute */
    public static array $scripts = [];

    public static function addScript(string $path): void
    {
        self::$scripts[] = $path;
    }

    /** @param array<string,mixed> $data */
    public static function render(string $view, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::partial($view, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    public static function partial(string $view, array $data = []): string
    {
        $file = Config::get('root') . '/src/Views/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $view");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } finally {
            $out = ob_get_clean();
        }
        return (string) $out;
    }
}
