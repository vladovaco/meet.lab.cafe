<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @var array<string,mixed> */
    private static array $values = [];

    public static function init(string $rootPath): void
    {
        $storage = Env::get('STORAGE_PATH', 'storage/audio');
        if (!str_starts_with($storage, '/')) {
            $storage = $rootPath . '/' . $storage;
        }
        self::$values = [
            'root'          => $rootPath,
            'app_name'      => Env::get('APP_NAME', 'Meet Lab.cafe'),
            'app_url'       => rtrim(Env::get('APP_URL', ''), '/'),
            'env'           => Env::get('APP_ENV', 'production'),
            'timezone'      => Env::get('APP_TIMEZONE', 'Europe/Bratislava'),
            'language'      => Env::get('APP_LANGUAGE', 'sk'),
            'session_secret'=> Env::get('SESSION_SECRET', 'insecure-default'),
            'db' => [
                'host' => Env::get('DB_HOST', '127.0.0.1'),
                'port' => Env::int('DB_PORT', 3306),
                'name' => Env::get('DB_NAME', 'meet_labcafe'),
                'user' => Env::get('DB_USER', 'root'),
                'pass' => Env::get('DB_PASS', ''),
            ],
            'stt' => [
                'provider'            => Env::get('STT_PROVIDER', 'elevenlabs'),
                'elevenlabs_key'      => Env::get('ELEVENLABS_API_KEY'),
                'elevenlabs_model'    => Env::get('ELEVENLABS_MODEL', 'scribe_v2'),
                'assemblyai_key'      => Env::get('ASSEMBLYAI_API_KEY'),
                'assemblyai_base_url' => rtrim(Env::get('ASSEMBLYAI_BASE_URL', 'https://api.eu.assemblyai.com'), '/'),
            ],
            'ai' => [
                'provider' => Env::get('AI_PROVIDER', 'claude'),
            ],
            'anthropic' => [
                'key'   => Env::get('ANTHROPIC_API_KEY'),
                'model' => Env::get('ANTHROPIC_MODEL', 'claude-opus-5'),
            ],
            'gemini' => [
                'key'   => Env::get('GEMINI_API_KEY'),
                'model' => Env::get('GEMINI_MODEL', 'gemini-3.8-flash'),
            ],
            'process_mode'  => Env::get('PROCESS_MODE', 'web'),
            'max_upload_mb' => Env::int('MAX_UPLOAD_MB', 300),
            'storage_path'  => $storage,
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $cur = self::$values;
        foreach ($parts as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return $default;
            }
            $cur = $cur[$p];
        }
        return $cur;
    }
}
