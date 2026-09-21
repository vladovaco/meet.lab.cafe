<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

final class Storage
{
    private const ALLOWED = [
        'audio/webm' => 'webm', 'video/webm' => 'webm',
        'audio/ogg' => 'ogg', 'application/ogg' => 'ogg', 'audio/opus' => 'ogg',
        'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3',
        'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/m4a' => 'm4a', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav', 'audio/vnd.wave' => 'wav',
        'audio/aac' => 'aac', 'audio/x-aac' => 'aac', 'audio/flac' => 'flac', 'audio/x-flac' => 'flac',
        'audio/amr' => 'amr', 'audio/3gpp' => '3gp', 'video/3gpp' => '3gp', 'audio/x-caf' => 'caf',
    ];

    /**
     * Uloží nahraný súbor do storage a vráti [relatívna cesta, mime, veľkosť].
     * @param array{tmp_name:string,name:string,size:int,error:int} $file
     * @return array{path:string,mime:string,size:int}
     */
    public static function storeUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::uploadError((int) $file['error']));
        }
        $max = (int) Config::get('max_upload_mb') * 1024 * 1024;
        if ($file['size'] > $max) {
            throw new \RuntimeException('Súbor je väčší než povolený limit ' . Config::get('max_upload_mb') . ' MB.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($file['tmp_name']) ?: '');
        $ext = self::ALLOWED[$mime] ?? null;
        if ($ext === null) {
            // fallback na príponu (niektoré webm/opus nahrávky finfo označí ako octet-stream)
            $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($origExt, array_unique(array_values(self::ALLOWED)), true)) {
                $ext = $origExt;
                $mime = array_search($origExt, self::ALLOWED, true) ?: 'application/octet-stream';
            } else {
                throw new \RuntimeException("Nepodporovaný formát súboru ($mime). Použite mp3, m4a, wav, webm, ogg alebo flac.");
            }
        }
        $rel = gmdate('Y/m') . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        $abs = self::absolute($rel);
        $dir = dirname($abs);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nedá sa vytvoriť adresár pre nahrávky.');
        }
        if (!move_uploaded_file($file['tmp_name'], $abs) && !rename($file['tmp_name'], $abs)) {
            throw new \RuntimeException('Nahrávku sa nepodarilo uložiť.');
        }
        return ['path' => $rel, 'mime' => $mime, 'size' => (int) $file['size']];
    }

    public static function absolute(string $relative): string
    {
        return rtrim((string) Config::get('storage_path'), '/') . '/' . ltrim($relative, '/');
    }

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Súbor je príliš veľký (limit servera). Zvýšte upload_max_filesize / post_max_size.',
            UPLOAD_ERR_PARTIAL => 'Súbor sa nahral iba čiastočne, skúste znova.',
            UPLOAD_ERR_NO_FILE => 'Nebol vybraný žiadny súbor.',
            default => 'Chyba pri nahrávaní súboru (kód ' . $code . ').',
        };
    }
}
