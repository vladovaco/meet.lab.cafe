<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $to, int $status = 302): never
    {
        header('Location: ' . url($to), true, $status);
        exit;
    }

    public static function notFound(string $message = 'Stránka sa nenašla'): never
    {
        http_response_code(404);
        echo View::render('errors/404', ['message' => $message]);
        exit;
    }

    public static function download(string $filename, string $content, string $mime = 'text/plain; charset=utf-8'): never
    {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }
}
