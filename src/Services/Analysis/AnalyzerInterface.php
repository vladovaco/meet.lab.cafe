<?php
declare(strict_types=1);

namespace App\Services\Analysis;

interface AnalyzerInterface
{
    /** @return array<string,mixed> štruktúrovaný zápis (viď MeetingAnalyzer::schema()) */
    public function analyze(array $meeting, array $segments, array $expectedParticipants, array $knownParticipants): array;
}
