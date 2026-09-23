<?php
declare(strict_types=1);

namespace App\Services\Analysis;

use App\Core\Config;
use App\Services\ConfigurationException;
use App\Services\Http;

/**
 * Alternatíva ku Claude: Google Gemini API (generateContent) so štruktúrovaným JSON výstupom.
 * Docs: https://ai.google.dev/api/generate-content
 */
final class GeminiAnalyzer implements AnalyzerInterface
{
    private string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null, private readonly string $baseUrl = 'https://generativelanguage.googleapis.com')
    {
        $apiKey ??= (string) Config::get('gemini.key');
        if ($apiKey === '') {
            throw new ConfigurationException('Chýba GEMINI_API_KEY v .env');
        }
        $this->apiKey = $apiKey;
        $this->model = $model ?? (string) Config::get('gemini.model', 'gemini-3.8-flash');
    }

    public function analyze(array $meeting, array $segments, array $expectedParticipants, array $knownParticipants): array
    {
        $system = AnalysisPrompt::system(AnalysisPrompt::language($meeting));
        $user = AnalysisPrompt::user($meeting, $segments, $expectedParticipants, $knownParticipants);

        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $user]]]],
            'generationConfig'  => [
                'responseMimeType' => 'application/json',
                'responseSchema'   => AnalysisPrompt::schemaForGemini(),
                'maxOutputTokens'  => 32768,
                'temperature'      => 0.2,
            ],
        ];

        $client = Http::client(1800, ['base_uri' => $this->baseUrl]);
        $response = $client->post('/v1beta/models/' . rawurlencode($this->model) . ':generateContent', [
            'headers' => ['x-goog-api-key' => $this->apiKey, 'Content-Type' => 'application/json'],
            'json'    => $body,
        ]);
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        if ($status >= 300 || !is_array($data)) {
            $msg = is_array($data) ? ($data['error']['message'] ?? json_encode($data, JSON_UNESCAPED_UNICODE)) : substr((string) $response->getBody(), 0, 300);
            throw new \RuntimeException("Gemini API zlyhalo (HTTP $status): $msg");
        }
        if (isset($data['promptFeedback']['blockReason'])) {
            throw new \RuntimeException('Gemini odmietol prepis spracovať: ' . $data['promptFeedback']['blockReason']);
        }
        $candidate = $data['candidates'][0] ?? null;
        if ($candidate === null) {
            throw new \RuntimeException('Gemini nevrátil žiadnu odpoveď.');
        }
        $finish = (string) ($candidate['finishReason'] ?? 'STOP');
        if ($finish === 'MAX_TOKENS') {
            throw new \RuntimeException('Odpoveď modelu bola príliš dlhá a skrátila sa. Skúste znova.');
        }
        if (!in_array($finish, ['STOP', 'FINISH_REASON_UNSPECIFIED'], true)) {
            throw new \RuntimeException("Gemini ukončil generovanie s dôvodom $finish.");
        }
        $json = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (isset($part['text']) && empty($part['thought'])) {
                $json .= $part['text'];
            }
        }
        $result = json_decode(trim($json), true);
        if (!is_array($result)) {
            throw new \RuntimeException('Model nevrátil platný JSON: ' . mb_substr($json, 0, 200));
        }
        $result['_usage'] = [
            'input_tokens'  => $data['usageMetadata']['promptTokenCount'] ?? null,
            'output_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? null,
            'model'         => $data['modelVersion'] ?? $this->model,
        ];
        return $result;
    }
}
