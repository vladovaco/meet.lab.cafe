<?php
declare(strict_types=1);

namespace App\Services\Analysis;

use App\Core\Config;

final class AnalyzerFactory
{
    public static function make(): AnalyzerInterface
    {
        return match ((string) Config::get('ai.provider', 'claude')) {
            'gemini' => new GeminiAnalyzer(),
            default  => new MeetingAnalyzer(),
        };
    }

    public static function label(): string
    {
        return Config::get('ai.provider') === 'gemini'
            ? 'Gemini · ' . Config::get('gemini.model')
            : 'Claude · ' . Config::get('anthropic.model');
    }

    public static function configured(): bool
    {
        return Config::get('ai.provider') === 'gemini' ? (bool) Config::get('gemini.key') : (bool) Config::get('anthropic.key');
    }
}
