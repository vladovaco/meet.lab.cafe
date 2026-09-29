<?php
declare(strict_types=1);

namespace App\Services\Analysis;

/**
 * Typ nahrávky (telefonát, porada, klientská prezentácia, konzultácia). Určuje, ako AI
 * interpretuje polia zápisu (rovnaká JSON schéma pre všetky typy) a ako sa volajú sekcie v UI.
 */
final class RecordingType
{
    public const DEFAULT = 'meeting';

    private const TYPES = [
        'call' => [
            'label'    => 'Telefonát',
            'icon'     => '📞',
            'title'    => 'Telefonát',
            'sections' => [
                'topics' => 'Priebeh hovoru', 'key_point' => 'Kľúčové informácie', 'decision' => 'Dohody',
                'open_question' => 'Otvorené otázky', 'tasks' => 'Úlohy a follow-up',
            ],
            'role' => 'Si asistent, ktorý robí stručné a vecné záznamy z pracovných telefonátov. Dostaneš diarizovaný prepis telefonátu (väčšinou dvaja účastníci).',
            'rules' => <<<'R'
- summary: 2–5 viet – kto s kým hovoril, prečo a s akým výsledkom.
- topics: len ak hovor mal viac tém; pri krátkom hovore 1–2 bloky.
- key_points: konkrétne fakty, ktoré zazneli (čísla, termíny, ceny, mená, kontakty, adresy).
- decisions: na čom sa strany dohodli.
- open_questions: čo ostalo nevyjasnené alebo treba overiť.
- action_items: follow-up po hovore (zavolať späť, poslať e-mail/ponuku, overiť…), s osobou a termínom, ak zazneli.
R,
        ],
        'meeting' => [
            'label'    => 'Porada',
            'icon'     => '👥',
            'title'    => 'Porada',
            'sections' => [
                'topics' => 'Priebeh porady', 'key_point' => 'Kľúčové body', 'decision' => 'Rozhodnutia',
                'open_question' => 'Otvorené otázky', 'tasks' => 'Úlohy z porady',
            ],
            'role' => 'Si skúsený zapisovateľ firemných porád. Dostaneš diarizovaný prepis porady.',
            'rules' => <<<'R'
- summary: 3–8 viet, čo bolo cieľom porady a k čomu sa dospelo.
- topics: chronologické bloky porady.
- key_points: najdôležitejšie fakty a informácie, ktoré zazneli (nie rozhodnutia ani úlohy).
- decisions: len to, na čom sa účastníci naozaj dohodli.
- open_questions: veci, ktoré ostali nedoriešené.
- action_items: konkrétne úlohy s osobou a termínom, ak zazneli.
R,
        ],
        'presentation' => [
            'label'    => 'Klientská prezentácia',
            'icon'     => '📊',
            'title'    => 'Prezentácia',
            'sections' => [
                'topics' => 'Priebeh prezentácie', 'key_point' => 'Čo sme prezentovali a reakcie klienta', 'decision' => 'Dohodnuté s klientom',
                'open_question' => 'Otázky a námietky klienta', 'tasks' => 'Ďalšie kroky',
            ],
            'role' => 'Si skúsený obchodný konzultant, ktorý robí záznam z prezentácie pre klienta. Dostaneš diarizovaný prepis stretnutia – na jednej strane prezentujúci (náš tím), na druhej klient.',
            'rules' => <<<'R'
- Rozlíš, kto patrí k prezentujúcim a kto ku klientovi (podľa kontextu: kto predstavuje riešenie, kto sa pýta na cenu, termíny, prínosy). Záznam píš z pohľadu prezentujúceho tímu.
- summary: 3–6 viet – čo sme klientovi predstavili, ako reagoval, aký je celkový dojem (záujem, váhanie, odmietnutie) a dohodnutý ďalší postup.
- topics: bloky prezentácie a diskusie.
- key_points: hlavné prezentované body a konkrétne reakcie klienta – čo ho zaujalo, čo považuje za dôležité, signály záujmu a nákupné signály, rozpočet, rozhodovací proces a kto rozhoduje, konkurencia, ak zazneli.
- decisions: na čom sa s klientom dohodlo (ďalšie stretnutie, pilot, zaslanie ponuky, cena…).
- open_questions: otázky a námietky klienta, najmä tie, ktoré neboli uspokojivo zodpovedané.
- action_items: ďalšie kroky na oboch stranách (poslať ponuku/materiály, pripraviť demo, klient dodá podklady…), s osobou a termínom, ak zazneli.
R,
        ],
        'consultation' => [
            'label'    => 'Rozhovor s terapeutom, coachom, lekárom',
            'icon'     => '💬',
            'title'    => 'Konzultácia',
            'sections' => [
                'topics' => 'Priebeh rozhovoru', 'key_point' => 'Kľúčové zistenia', 'decision' => 'Odporúčania a dohodnutý plán',
                'open_question' => 'Otvorené témy', 'tasks' => 'Úlohy do ďalšieho stretnutia',
            ],
            'role' => 'Si pozorný a diskrétny asistent, ktorý robí osobný záznam z rozhovoru klienta s odborníkom (terapeut, coach alebo lekár). Záznam slúži klientovi, aby si zapamätal, čo sa preberalo a čo mu odborník odporučil. Dostaneš diarizovaný prepis rozhovoru.',
            'rules' => <<<'R'
- Rozlíš odborníka a klienta podľa kontextu (kto kladie otázky, kto dáva odporúčania). Píš vecne, empaticky a bez hodnotenia, v tretej osobe („klient“, „odborník“ alebo ich mená).
- Zaznamenaj len to, čo odborník alebo klient naozaj povedal. NEPRIDÁVAJ vlastné diagnózy, liečbu, rady ani interpretácie. Lieky, dávkovanie, cvičenia a termíny uveď presne tak, ako zazneli; ak niečo nie je jasné, daj to do open_questions namiesto hádania.
- summary: 3–6 viet – s čím klient prišiel, čo sa preberalo a k čomu rozhovor dospel.
- topics: preberané témy v poradí, ako prišli.
- key_points: dôležité zistenia a postrehy (o situácii klienta, jeho pocitoch, pokroku od minula), ako ich formulovali účastníci.
- decisions: odporúčania odborníka a dohodnutý plán (liečba, cvičenia, zmeny, ďalšie vyšetrenie, ďalšie stretnutie).
- open_questions: otázky, ktoré ostali nezodpovedané, a témy na ďalšie stretnutie.
- action_items: úlohy klienta do ďalšieho stretnutia a úlohy odborníka (napr. poslať materiály, vystaviť recept), ak zazneli.
- tags: neutrálne tematické štítky; nepoužívaj citlivé zdravotné diagnózy ako štítky.
R,
        ],
    ];

    /** @return array<string, string> kľúč => popis (pre select) */
    public static function options(): array
    {
        return array_map(static fn($t) => $t['icon'] . ' ' . $t['label'], self::TYPES);
    }

    public static function normalize(?string $type): string
    {
        return isset(self::TYPES[(string) $type]) ? (string) $type : self::DEFAULT;
    }

    public static function get(?string $type): array
    {
        return self::TYPES[self::normalize($type)];
    }

    public static function label(?string $type): string
    {
        return self::get($type)['label'];
    }

    public static function icon(?string $type): string
    {
        return self::get($type)['icon'];
    }

    /** Predvolený názov nahrávky, napr. „Telefonát 29. 9. 2026 10:15“. */
    public static function defaultTitle(?string $type): string
    {
        return self::get($type)['title'];
    }

    /** Nadpis sekcie zápisu: topics | key_point | decision | open_question | tasks. */
    public static function section(?string $type, string $key): string
    {
        return self::get($type)['sections'][$key] ?? $key;
    }

    /** Regex predvolených názvov (ak ich používateľ nezmenil, AI navrhne vlastný). */
    public static function defaultTitlePattern(): string
    {
        $names = array_map(static fn($t) => preg_quote($t['title'], '/'), self::TYPES);
        return '/^(' . implode('|', array_merge($names, ['Nahrávka', 'Záznam'])) . ')\s/u';
    }
}
