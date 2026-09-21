<?php
declare(strict_types=1);

namespace App\Services\Transcription;

/** Jednotný výsledok prepisu bez ohľadu na poskytovateľa. */
final class TranscriptResult
{
    /**
     * @param list<array{speaker:?string,start:float,end:float,text:string}> $segments
     */
    public function __construct(
        public readonly string $text,
        public readonly array $segments,
        public readonly ?string $language = null,
        public readonly ?float $duration = null,
        public readonly ?string $providerJobId = null,
    ) {
    }

    /** Štatistika rečníkov: label => [talk_seconds, word_count]. */
    public function speakerStats(): array
    {
        $stats = [];
        foreach ($this->segments as $s) {
            $label = $s['speaker'] ?? 'speaker_0';
            $stats[$label] ??= ['talk_seconds' => 0.0, 'word_count' => 0];
            $stats[$label]['talk_seconds'] += max(0.0, $s['end'] - $s['start']);
            $stats[$label]['word_count'] += count(preg_split('/\s+/u', trim($s['text']), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }
        return $stats;
    }

    /**
     * Zoskupí slová (s časmi a rečníkom) do segmentov: nový segment pri zmene rečníka,
     * dlhšej pauze alebo po ~45 s súvislej reči.
     *
     * @param list<array{text:string,start:float,end:float,speaker:?string}> $words
     */
    public static function segmentsFromWords(array $words, float $gap = 1.5, float $maxLen = 45.0): array
    {
        $segments = [];
        $cur = null;
        foreach ($words as $w) {
            $text = $w['text'];
            if (trim($text) === '') {
                continue;
            }
            $speaker = $w['speaker'] ?? null;
            $startNew = $cur === null
                || $cur['speaker'] !== $speaker
                || ($w['start'] - $cur['end']) > $gap
                || ($w['end'] - $cur['start']) > $maxLen && preg_match('/[.!?]$/', $cur['text']);
            if ($startNew) {
                if ($cur !== null) {
                    $cur['text'] = trim($cur['text']);
                    $segments[] = $cur;
                }
                $cur = ['speaker' => $speaker, 'start' => (float) $w['start'], 'end' => (float) $w['end'], 'text' => ''];
            }
            $cur['text'] .= (str_ends_with($cur['text'], ' ') || $cur['text'] === '' || preg_match('/^[,.!?;:]/', $text) ? '' : ' ') . trim($text);
            $cur['end'] = max($cur['end'], (float) $w['end']);
        }
        if ($cur !== null) {
            $cur['text'] = trim($cur['text']);
            $segments[] = $cur;
        }
        return $segments;
    }
}
