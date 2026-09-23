<?php
declare(strict_types=1);

namespace App\Services\Transcription;

use App\Services\Http;

/**
 * AssemblyAI – alternatívny poskytovateľ (EU endpoint, diarizácia cez speaker_labels).
 * Docs: https://www.assemblyai.com/docs/api-reference/transcripts/submit
 */
final class AssemblyAI implements TranscriberInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.eu.assemblyai.com',
    ) {
    }

    public function name(): string
    {
        return 'assemblyai';
    }

    public function transcribe(string $filePath, ?string $language, ?int $numSpeakers = null): TranscriptResult
    {
        $client = Http::client(1800, ['base_uri' => $this->baseUrl, 'headers' => ['authorization' => $this->apiKey]]);

        // 1) upload súboru
        $up = $client->post('/v2/upload', [
            'headers' => ['content-type' => 'application/octet-stream'],
            'body'    => fopen($filePath, 'rb'),
        ]);
        $upData = json_decode((string) $up->getBody(), true);
        if ($up->getStatusCode() >= 300 || empty($upData['upload_url'])) {
            throw new \RuntimeException('AssemblyAI upload zlyhal: ' . substr((string) $up->getBody(), 0, 300));
        }

        // 2) zadanie prepisu
        $payload = [
            'audio_url'      => $upData['upload_url'],
            'speaker_labels' => true,
            'punctuate'      => true,
            'format_text'    => true,
        ];
        if ($language) {
            $payload['language_code'] = $language;
        } else {
            $payload['language_detection'] = true;
        }
        if ($numSpeakers !== null && $numSpeakers > 0) {
            $payload['speakers_expected'] = $numSpeakers;
        }
        $sub = $client->post('/v2/transcript', ['json' => $payload]);
        $subData = json_decode((string) $sub->getBody(), true);
        if ($sub->getStatusCode() >= 300 || empty($subData['id'])) {
            throw new \RuntimeException('AssemblyAI submit zlyhal: ' . substr((string) $sub->getBody(), 0, 300));
        }
        $id = (string) $subData['id'];

        // 3) polling
        $deadline = time() + 1700;
        do {
            sleep(5);
            $poll = $client->get('/v2/transcript/' . $id);
            $data = json_decode((string) $poll->getBody(), true) ?: [];
            $status = $data['status'] ?? 'error';
            if ($status === 'error') {
                throw new \RuntimeException('AssemblyAI chyba: ' . ($data['error'] ?? 'neznáma'));
            }
        } while ($status !== 'completed' && time() < $deadline);

        if ($status !== 'completed') {
            throw new \RuntimeException('AssemblyAI: vypršal čas čakania na prepis.');
        }

        $segments = [];
        foreach ($data['utterances'] ?? [] as $u) {
            $segments[] = [
                'speaker' => 'speaker_' . strtolower((string) ($u['speaker'] ?? '0')),
                'start'   => ((float) ($u['start'] ?? 0)) / 1000,
                'end'     => ((float) ($u['end'] ?? 0)) / 1000,
                'text'    => trim((string) ($u['text'] ?? '')),
            ];
        }
        if ($segments === [] && !empty($data['text'])) {
            $segments[] = ['speaker' => 'speaker_0', 'start' => 0.0, 'end' => ((float) ($data['audio_duration'] ?? 0)), 'text' => (string) $data['text']];
        }

        return new TranscriptResult(
            text: (string) ($data['text'] ?? ''),
            segments: $segments,
            language: $data['language_code'] ?? $language,
            duration: isset($data['audio_duration']) ? (float) $data['audio_duration'] : null,
            providerJobId: $id,
        );
    }
}
