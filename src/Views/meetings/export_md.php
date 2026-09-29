<?php
$names = [];
foreach ($speakers as $i => $s) {
    $names[$s['speaker_label']] = $s['participant_name'] ?? $s['suggested_name'] ?? ('Rečník ' . ($i + 1));
}
$byKind = ['key_point' => [], 'decision' => [], 'open_question' => []];
foreach ($points as $p) {
    $byKind[$p['kind']][] = $p['text'];
}
$section = static fn(string $key): string => \App\Services\Analysis\RecordingType::section($meeting['recording_type'] ?? null, $key);
echo '# ' . $meeting['title'] . "\n\n";
echo '**Typ:** ' . \App\Services\Analysis\RecordingType::label($meeting['recording_type'] ?? null) . "  \n";
echo '**Dátum:** ' . format_date($meeting['meeting_date'], 'j. n. Y H:i') . "  \n";
if ($meeting['location']) echo '**Miesto:** ' . $meeting['location'] . "  \n";
if ($meeting['audio_duration']) echo '**Trvanie:** ' . format_duration((float) $meeting['audio_duration']) . "  \n";
if ($speakers) echo '**Účastníci:** ' . implode(', ', array_values($names)) . "  \n";
if ($tags) echo '**Štítky:** ' . implode(', ', array_map(fn($t) => '#' . $t['name'], $tags)) . "  \n";
echo "\n## Súhrn\n\n" . trim((string) $meeting['summary']) . "\n";
if ($topics) {
    echo "\n## " . $section('topics') . "\n";
    foreach ($topics as $t) {
        echo "\n### " . ($t['start_sec'] !== null ? '[' . format_duration((float) $t['start_sec']) . '] ' : '') . $t['title'] . "\n\n" . $t['summary'] . "\n";
    }
}
foreach ([['key_point', $section('key_point')], ['decision', $section('decision')], ['open_question', $section('open_question')]] as [$kind, $label]) {
    if ($byKind[$kind]) {
        echo "\n## $label\n\n";
        foreach ($byKind[$kind] as $text) echo "- $text\n";
    }
}
if ($actionItems) {
    echo "\n## " . $section('tasks') . "\n\n";
    foreach ($actionItems as $a) {
        $who = $a['participant_name'] ?? $a['assignee_name'] ?? 'nepriradené';
        echo '- [' . ($a['status'] === 'done' ? 'x' : ' ') . '] ' . $a['description'] . ' — **' . $who . '**' . ($a['due_date'] ? ' (do ' . date('j. n. Y', strtotime($a['due_date'])) . ')' : '') . ($a['priority'] === 'high' ? ' ⚠️' : '') . "\n";
    }
}
if ($segments) {
    echo "\n## Prepis\n\n";
    foreach ($segments as $seg) {
        echo '**[' . format_duration((float) $seg['start_sec']) . '] ' . ($names[$seg['speaker_label']] ?? $seg['speaker_label']) . ':** ' . $seg['text'] . "\n\n";
    }
}
