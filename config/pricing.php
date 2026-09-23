<?php
/**
 * Cenník poskytovateľov na odhad nákladov (USD). Aktualizujte podľa aktuálnych cenníkov.
 * Kľúče modelov sa porovnávajú ako prefix (napr. 'claude-opus-5' platí aj pre 'claude-opus-5-2026...').
 * Stav: september 2026.
 */
return [
    // prepis: USD za hodinu audia
    'stt' => [
        'elevenlabs' => 0.22,   // Scribe v2, dávkový prepis
        'assemblyai' => 0.17,   // Universal-2 0.15 + diarizácia 0.02
    ],
    // LLM: USD za 1 milión tokenov [vstup, výstup]
    'llm' => [
        'claude-fable-5'      => [10.00, 50.00],
        'claude-opus-5'       => [5.00, 25.00],
        'claude-opus-4'       => [5.00, 25.00],
        'claude-sonnet-5'     => [2.00, 10.00],
        'claude-sonnet-4'     => [3.00, 15.00],
        'claude-haiku-4'      => [1.00, 5.00],
        'gemini-3.8-flash'    => [0.75, 3.75],   // do 31. 12. 2026, potom 1.50 / 7.50
        'gemini-3.1-pro'      => [2.00, 12.00],
        'gemini-3.5-flash-lite' => [0.10, 0.40],
        'gemini-2.5-flash'    => [0.30, 2.50],
        'gemini-2.5-pro'      => [1.25, 10.00],
    ],
    // kurz na zobrazenie v EUR (0 = zobrazovať iba USD)
    'eur_rate' => 0.92,
];
