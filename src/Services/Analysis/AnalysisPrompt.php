<?php
declare(strict_types=1);

namespace App\Services\Analysis;

use App\Core\Config;

/** Spoločný prompt a JSON schéma zápisu – používajú ho všetci AI poskytovatelia (Claude, Gemini). */
final class AnalysisPrompt
{
    public static function system(string $lang): string
    {
        return <<<SYS
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
Odpovedz výhradne JSON objektom podľa zadanej schémy.
SYS;
    }

    /**
     * @param array<string,mixed> $meeting
     * @param list<array{speaker:?string,start:float,end:float,text:string}> $segments
     */
    public static function user(array $meeting, array $segments, array $expectedParticipants, array $knownParticipants): string
    {
        $transcript = self::formatTranscript($segments);
        $expected = $expectedParticipants === []
            ? 'Neuvedení.'
            : implode("\n", array_map(
                fn($p) => sprintf('- %s%s%s', $p['name'], $p['position'] ? ' (' . $p['position'] . ')' : '', $p['aliases'] ? ' – prezývky: ' . $p['aliases'] : ''),
                $expectedParticipants
            ));
        $known = $knownParticipants === []
            ? 'Databáza je zatiaľ prázdna.'
            : implode(', ', array_map(fn($p) => $p['name'], array_slice($knownParticipants, 0, 300)));

        return <<<USR
Názov porady: {$meeting['title']}
Dátum porady: {$meeting['meeting_date']} (UTC)
Očakávaní účastníci:
{$expected}

Databáza známych účastníkov (na priradenie mien): {$known}

=== PREPIS ===
{$transcript}
=== KONIEC PREPISU ===
USR;
    }

    public static function language(array $meeting): string
    {
        return (string) ($meeting['language'] ?: Config::get('language', 'sk'));
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

    /** JSON schéma štruktúrovaného výstupu (štandardný JSON Schema). */
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

    /**
     * Prevod na podmnožinu OpenAPI schémy, ktorú akceptuje Gemini `responseSchema`
     * (bez additionalProperties, typ ako reťazec + nullable namiesto pola typov).
     */
    public static function schemaForGemini(?array $schema = null): array
    {
        $schema ??= self::schema();
        $out = [];
        foreach ($schema as $k => $v) {
            if ($k === 'additionalProperties') {
                continue;
            }
            if ($k === 'type' && is_array($v)) {
                $types = array_values(array_diff($v, ['null']));
                $out['type'] = $types[0] ?? 'string';
                if (in_array('null', $v, true)) {
                    $out['nullable'] = true;
                }
                continue;
            }
            if ($k === 'properties') {
                $out['properties'] = [];
                foreach ($v as $name => $sub) {
                    $out['properties'][$name] = self::schemaForGemini($sub);
                }
                $out['propertyOrdering'] = array_keys($v);
                continue;
            }
            if ($k === 'items') {
                $out['items'] = self::schemaForGemini($v);
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }
}
