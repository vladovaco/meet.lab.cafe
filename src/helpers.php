<?php
declare(strict_types=1);

use App\Core\Config;

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/'): string
{
    if (preg_match('#^https?://#', $path)) {
        return $path;
    }
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $base . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = Config::get('root') . '/public/' . ltrim($path, '/');
    $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '1';
    return url($path) . '?v=' . $v;
}

function format_duration(float|int|null $seconds): string
{
    if ($seconds === null) {
        return '–';
    }
    $s = (int) round((float) $seconds);
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $sec = $s % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
}

function format_date(?string $datetime, string $format = 'j. n. Y H:i'): string
{
    if (!$datetime) {
        return '–';
    }
    try {
        $dt = (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string) Config::get('timezone')));
        $days = ['Monday' => 'pondelok', 'Tuesday' => 'utorok', 'Wednesday' => 'streda', 'Thursday' => 'štvrtok', 'Friday' => 'piatok', 'Saturday' => 'sobota', 'Sunday' => 'nedeľa'];
        $short = ['Mon' => 'po', 'Tue' => 'ut', 'Wed' => 'st', 'Thu' => 'št', 'Fri' => 'pi', 'Sat' => 'so', 'Sun' => 'ne'];
        $out = $dt->format($format);
        if (str_contains($format, 'l')) {
            $out = str_replace($dt->format('l'), $days[$dt->format('l')] ?? $dt->format('l'), $out);
        }
        if (str_contains($format, 'D')) {
            $out = str_replace($dt->format('D'), $short[$dt->format('D')] ?? $dt->format('D'), $out);
        }
        return $out;
    } catch (Throwable) {
        return $datetime;
    }
}

function status_label(string $status): string
{
    return match ($status) {
        'queued'       => 'Vo fronte',
        'transcribing' => 'Prepisuje sa',
        'transcribed'  => 'Prepísané',
        'analyzing'    => 'Analyzuje sa',
        'done'         => 'Hotovo',
        'error'        => 'Chyba',
        default        => $status,
    };
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out !== '' ? $out : '?';
}

/** Farba pre label rečníka (stabilná podľa poradia). */
function speaker_color(int $index): string
{
    $palette = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#84cc16'];
    return $palette[$index % count($palette)];
}
