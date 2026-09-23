<?php
declare(strict_types=1);

namespace App\Services\Analysis;

use Anthropic\Client;
use Anthropic\Lib\Streaming\MessageAccumulator;
use App\Core\Config;
use App\Services\ConfigurationException;
use App\Services\Http;

/**
 * Zo surového diarizovaného prepisu vytvorí štruktúrovaný zápis pomocou Claude API:
 * súhrn, témy, kľúčové body, rozhodnutia, otvorené otázky, úlohy s priradením
 * a návrh mien rečníkov podľa kontextu rozhovoru.
 */
final class MeetingAnalyzer implements AnalyzerInterface
{
    private Client $client;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $apiKey ??= (string) Config::get('anthropic.key');
        if ($apiKey === '') {
            throw new ConfigurationException('Chýba ANTHROPIC_API_KEY v .env');
        }
        // vlastný HTTP klient s 30-min limitom (SDK by inak použil Guzzle bez timeoutu → "Unable to read from stream")
        $this->client = new Client(apiKey: $apiKey, requestOptions: ['transporter' => Http::client(1800), 'timeout' => 1800.0]);
        $this->model = $model ?? (string) Config::get('anthropic.model', 'claude-opus-5');
    }

    /**
     * @param array<string,mixed> $meeting riadok z tabuľky meetings
     * @param list<array{speaker:?string,start:float,end:float,text:string}> $segments
     * @param list<array<string,mixed>> $expectedParticipants účastníci zadaní používateľom pri vytvorení porady
     * @param list<array<string,mixed>> $knownParticipants celá databáza účastníkov (na priraďovanie mien)
     * @return array<string,mixed> dekódovaný JSON podľa schémy
     */
    public function analyze(array $meeting, array $segments, array $expectedParticipants, array $knownParticipants): array
    {
        $system = AnalysisPrompt::system(AnalysisPrompt::language($meeting));
        $user = AnalysisPrompt::user($meeting, $segments, $expectedParticipants, $knownParticipants);

        $params = [
            'maxTokens'    => 32000,
            'messages'     => [['role' => 'user', 'content' => $user]],
            'model'        => $this->model,
            'system'       => [['type' => 'text', 'text' => $system]],
            'thinking'     => ['type' => 'adaptive'],
            'outputConfig' => ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
        ];
        try {
            $stream = $this->client->messages->createStream(...$params);
            $acc = MessageAccumulator::forMessages();
            foreach ($stream as $event) {
                $acc->accumulate($event);
            }
            $message = $acc->message();
        } catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
            throw $e; // chyba API (401, 429, 500…) – nemá zmysel opakovať inak
        } catch (\Throwable $e) {
            // prerušený stream (timeout, sieť) – skús ešte raz bez streamovania
            error_log('[analyzer] stream zlyhal, skúšam bez streamu: ' . $e->getMessage());
            $message = $this->client->messages->create(...$params);
        }

        if ($message->stopReason === 'refusal') {
            throw new \RuntimeException('Model odmietol spracovať prepis (' . ($message->stopDetails?->category ?? 'bez kategórie') . ').');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new \RuntimeException('Odpoveď modelu bola príliš dlhá a skrátila sa. Skúste znova.');
        }
        $json = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json .= $block->text;
            }
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Model nevrátil platný JSON: ' . substr($json, 0, 200));
        }
        $data['_usage'] = [
            'input_tokens'  => $message->usage->inputTokens ?? null,
            'output_tokens' => $message->usage->outputTokens ?? null,
            'model'         => $this->model,
        ];
        return $data;
    }

    /** @param list<array{speaker:?string,start:float,end:float,text:string}> $segments */
    public static function formatTranscript(array $segments): string
    {
        return AnalysisPrompt::formatTranscript($segments);
    }

    /** JSON schéma štruktúrovaného výstupu. */
    public static function schema(): array
    {
        return AnalysisPrompt::schema();
    }
}
