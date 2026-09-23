<?php
declare(strict_types=1);

namespace App\Services\Transcription;

use App\Services\Http;

/**
 * ElevenLabs Scribe (speech-to-text) – 90+ jazykov vrátane slovenčiny,
 * diarizácia až 32 rečníkov, časové značky na úrovni slov.
 * Docs: https://elevenlabs.io/docs/api-reference/speech-to-text/convert
 */
final class ElevenLabsScribe implements TranscriberInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $modelId = 'scribe_v2',
        private readonly string $baseUrl = 'https://api.elevenlabs.io',
    ) {
    }

    public function name(): string
    {
        return 'elevenlabs:' . $this->modelId;
    }

    public function transcribe(string $filePath, ?string $language, ?int $numSpeakers = null): TranscriptResult
    {
        $client = Http::client(1800, ['base_uri' => $this->baseUrl]);
        $multipart = [
            ['name' => 'model_id', 'contents' => $this->modelId],
            ['name' => 'diarize', 'contents' => 'true'],
            ['name' => 'tag_audio_events', 'contents' => 'false'],
            ['name' => 'timestamps_granularity', 'contents' => 'word'],
            ['name' => 'file', 'contents' => fopen($filePath, 'rb'), 'filename' => basename($filePath)],
        ];
        if ($language) {
            $multipart[] = ['name' => 'language_code', 'contents' => $language];
        }
        if ($numSpeakers !== null && $numSpeakers > 0) {
            $multipart[] = ['name' => 'num_speakers', 'contents' => (string) min(32, $numSpeakers)];
        }

        $response = $client->post('/v1/speech-to-text', [
            'headers'   => ['xi-api-key' => $this->apiKey, 'Accept' => 'application/json'],
            'multipart' => $multipart,
            'http_errors' => false,
        ]);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        if ($status >= 300 || !is_array($data)) {
            $detail = is_array($data) ? json_encode($data['detail'] ?? $data, JSON_UNESCAPED_UNICODE) : substr($body, 0, 300);
            throw new \RuntimeException("ElevenLabs STT zlyhal (HTTP $status): $detail");
        }
        // multikanálové audio vracia pole transcripts – zoberieme prvý
        if (!isset($data['words']) && isset($data['transcripts'][0])) {
            $data = $data['transcripts'][0];
        }

        $words = [];
        foreach ($data['words'] ?? [] as $w) {
            if (($w['type'] ?? 'word') !== 'word') {
                continue;
            }
            $words[] = [
                'text'    => (string) $w['text'],
                'start'   => (float) ($w['start'] ?? 0),
                'end'     => (float) ($w['end'] ?? 0),
                'speaker' => isset($w['speaker_id']) ? (string) $w['speaker_id'] : 'speaker_0',
            ];
        }
        $segments = TranscriptResult::segmentsFromWords($words);
        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '' && $segments !== []) {
            $text = implode("\n", array_column($segments, 'text'));
        }
        $duration = isset($data['audio_duration_secs']) ? (float) $data['audio_duration_secs']
            : ($segments !== [] ? (float) end($segments)['end'] : null);

        return new TranscriptResult(
            text: $text,
            segments: $segments,
            language: isset($data['language_code']) ? (string) $data['language_code'] : $language,
            duration: $duration,
            providerJobId: isset($data['transcription_id']) ? (string) $data['transcription_id'] : null,
        );
    }
}
