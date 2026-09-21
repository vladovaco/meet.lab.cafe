<?php
declare(strict_types=1);

namespace App\Services\Transcription;

interface TranscriberInterface
{
    public function name(): string;

    /**
     * Prepíše audio súbor s diarizáciou rečníkov.
     *
     * @param string      $filePath absolútna cesta k audiu
     * @param string|null $language ISO-639-1 kód (napr. "sk"), null = autodetekcia
     * @param int|null    $numSpeakers očakávaný počet rečníkov (pomôcka pre diarizáciu)
     */
    public function transcribe(string $filePath, ?string $language, ?int $numSpeakers = null): TranscriptResult;
}
