<?php
declare(strict_types=1);

namespace App\Services\Transcription;

use App\Core\Config;

final class TranscriberFactory
{
    public static function make(): TranscriberInterface
    {
        $provider = (string) Config::get('stt.provider', 'elevenlabs');
        return match ($provider) {
            'assemblyai' => new AssemblyAI(
                self::requireKey(Config::get('stt.assemblyai_key'), 'ASSEMBLYAI_API_KEY'),
                (string) Config::get('stt.assemblyai_base_url'),
            ),
            default => new ElevenLabsScribe(
                self::requireKey(Config::get('stt.elevenlabs_key'), 'ELEVENLABS_API_KEY'),
                (string) Config::get('stt.elevenlabs_model', 'scribe_v2'),
            ),
        };
    }

    private static function requireKey(?string $key, string $envName): string
    {
        if (!$key) {
            throw new \App\Services\ConfigurationException("Chýba API kľúč $envName v .env");
        }
        return $key;
    }
}
