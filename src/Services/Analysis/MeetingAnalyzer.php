<?php
declare(strict_types=1);

namespace App\Services\Analysis;

use Anthropic\Client;
use Anthropic\Lib\Streaming\MessageAccumulator;
use App\Core\Config;

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
            throw new \App\Services\ConfigurationException('Chýba ANTHROPIC_API_KEY v .env');
        }
        $this->client = new Client(apiKey: $apiKey);
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
        $transcript = self::formatTranscript($segments);
        $lang = $meeting['language'] ?: (string) Config::get('language', 'sk');

        $expected = $expectedParticipants === []
            ? 'Neuvedení.'
            : implode("\n", array_map(
                fn($p) => sprintf('- %s%s%s', $p['name'], $p['position'] ? ' (' . $p['position'] . ')' : '', $p['aliases'] ? ' – prezývky: ' . $p['aliases'] : ''),
                $expectedParticipants
            ));
        $known = $knownParticipants === []
            ? 'Databáza je zatiaľ prázdna.'
            : implode(', ', array_map(fn($p) => $p['name'], array_slice($knownParticipants, 0, 300)));

        $system = <<<SYS
Si skúsený zapisovateľ firemných porád. Dostaneš diarizovaný prepis porady (rečníci sú označení technickými labelmi ako speaker_0, speaker_1 a časovou značkou [mm:ss]). Prepis pochádza z automatického rozpoznávania reči, takže môže obsahovať preklepy, chýbajúcu interpunkciu a zle rozpoznané mená – interpretuj ho s rozumom.

Tvoja úloha: vytvoriť presný, vecný a štruktúrovaný zápis z porady v jazyku porady (kód jazyka: {$lang}). Nevymýšľaj si nič, čo v prepise nezaznelo. Ak niečo nie je jasné, radšej to vynechaj alebo označ ako otvorenú otázku.

Pravidlá:
- summary: 3–8 viet, čo bolo cieľom porady a k čomu sa dospelo.
- topics: chronologické bloky porady (téma + 2–5 viet zhrnutia + čas začiatku v sekundách podľa časovej značky).
- key_points: najdôležitejšie fakty a informácie, ktoré zazneli (nie rozhodnutia ani úlohy).
- decisions: len to, na čom sa účastníci naozaj dohodli.
- open_questions: veci, ktoré ostali nedoriešené.
- action_items: konkrétne úlohy. assignee = meno osoby tak, ako sa dá z rozhovoru odvodiť (alebo label rečníka, ak meno nepoznáš, napr. "speaker_1"); ak nikto nie je zodpovedný, null. due_date vo formáte YYYY-MM-DD iba ak zaznel termín (relatívne termíny ako "do piatku" prepočítaj podľa dátumu porady), inak null. source_quote = krátky doslovný úryvok z prepisu, z ktorého úloha vyplýva.
- speakers: pre každý label rečníka odhadni skutočné meno podľa kontextu (oslovenia ako "Peter, čo ty na to", sebapredstavenie, kto koho oslovuje). Preferuj mená zo zoznamu očakávaných účastníkov alebo databázy. confidence 0–1. Ak meno nevieš odhadnúť, name = null.
- tags: 2–5 krátkych tematických štítkov (jedno- až dvojslovné, malé písmená).
- title_suggestion: výstižný názov porady (max 8 slov).
SYS;

        $user = <<<USR
Názov porady: {$meeting['title']}
Dátum porady: {$meeting['meeting_date']} (UTC)
Očakávaní účastníci:
{$expected}

Databáza známych účastníkov (na priradenie mien): {$known}

=== PREPIS ===
{$transcript}
=== KONIEC PREPISU ===
USR;

        $stream = $this->client->messages->createStream(
            maxTokens: 32000,
            messages: [['role' => 'user', 'content' => $user]],
            model: $this->model,
            system: [['type' => 'text', 'text' => $system]],
            thinking: ['type' => 'adaptive'],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
        );
        $acc = MessageAccumulator::forMessages();
        foreach ($stream as $event) {
            $acc->accumulate($event);
        }
        $message = $acc->message();

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
        $lines = [];
        foreach ($segments as $s) {
            $t = (int) $s['start'];
            $lines[] = sprintf('[%02d:%02d] %s: %s', intdiv($t, 60), $t % 60, $s['speaker'] ?? 'speaker_0', $s['text']);
        }
        return implode("\n", $lines);
    }

    /** JSON schéma štruktúrovaného výstupu. */
    public static function schema(): array
    {
        $str = ['type' => 'string'];
        $nullStr = ['type' => ['string', 'null']];
        $strList = ['type' => 'array', 'items' => $str];
        $obj = fn(array $props) => ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];

        return $obj([
            'title_suggestion' => $str,
            'summary'          => $str,
            'topics'           => ['type' => 'array', 'items' => $obj([
                'title'     => $str,
                'summary'   => $str,
                'start_sec' => ['type' => ['number', 'null']],
            ])],
            'key_points'     => $strList,
            'decisions'      => $strList,
            'open_questions' => $strList,
            'action_items'   => ['type' => 'array', 'items' => $obj([
                'description'  => $str,
                'assignee'     => $nullStr,
                'due_date'     => $nullStr,
                'priority'     => ['type' => 'string', 'enum' => ['low', 'normal', 'high']],
                'source_quote' => $nullStr,
            ])],
            'speakers' => ['type' => 'array', 'items' => $obj([
                'label'      => $str,
                'name'       => $nullStr,
                'confidence' => ['type' => 'number'],
                'reason'     => $str,
            ])],
            'tags' => $strList,
        ]);
    }
}
