<?php

// Åtgärder (docs/spec.md 11.3, T43): typer, statusar, effektnivåer, malltexter och evidensmeningar. Talen i
// platshållarna kommer från databasen. Alla språk måste ha samma nycklar.

return [
    'types' => [
        'source_gap' => 'Källgap',
        'owned_content' => 'Eget innehåll',
        'new_competitor' => 'Ny konkurrent',
        'crawler_access' => 'Robotåtkomst',
        'fact_correction' => 'Faktarättelse',
        'own' => 'Egen åtgärd',
    ],
    'statuses' => [
        'new' => 'Ny',
        'planned' => 'Planerad',
        'in_progress' => 'Pågår',
        'done' => 'Klar',
        'declined' => 'Avvisad',
        'expired' => 'Utgången',
    ],
    'impact_levels' => [
        1 => 'Mycket låg',
        2 => 'Låg',
        3 => 'Medel',
        4 => 'Hög',
        5 => 'Mycket hög',
    ],
    'templates' => [
        'source_gap' => [
            'title' => 'Bli omnämnda på :domain',
            'description' => 'AI-tjänsterna citerar :domain när de nämner era konkurrenter, men inte er. Innehåll om er där kan göra att ni tas med i samma svar.',
            'steps' => [
                'Se vilka sidor på :domain som citeras och vad de handlar om.',
                'Kontakta redaktionen eller ägaren av :domain och föreslå innehåll där ni kan nämnas.',
                'Se till att ni finns med korrekt och aktuell information där det är naturligt.',
            ],
        ],
        'owned_content' => [
            'title' => 'Skapa egna sidor om ”:topic”',
            'description' => 'Ni syns lite på promptarna om ”:topic”, och AI-tjänsterna citerar aldrig era egna sidor där. Sidor som svarar direkt på frågorna ger tjänsterna något att hänvisa till.',
            'steps' => [
                'Gå igenom promptarna om ”:topic” och hitta frågorna ni inte svarar på i dag.',
                'Skriv eller uppdatera sidor som svarar direkt och konkret på dem.',
                'Länka sidorna från relevanta ställen på webbplatsen och i sitemap.',
            ],
        ],
        'new_competitor' => [
            'title' => 'Överväg :name som konkurrent',
            'description' => ':name nämns ofta i svaren i :market men är inte registrerad som konkurrent. Som registrerad konkurrent mäts :name i share of voice och larm.',
            'steps' => [
                'Se svaren där :name nämns och bedöm om ni konkurrerar om samma kunder.',
                'Lägg till :name som konkurrent med alias och domäner om det stämmer.',
            ],
        ],
        'crawler_access' => [
            'title' => ':bot hämtar inte era sidor',
            'description' => ':bot har inte hämtat några sidor från er under perioden, trots att andra AI-robotar har gjort det. Då kan tjänsten sakna aktuellt innehåll om er.',
            'steps' => [
                'Kontrollera att robots.txt inte blockerar :bot.',
                'Kontrollera att brandvägg, CDN eller botskydd inte stoppar :bot.',
                'Se till att sitemap är uppdaterad och tillgänglig.',
            ],
        ],
        'fact_correction' => [
            'title' => 'Rätta felaktig uppgift: :fact',
            'description' => 'AI-svar anger en uppgift som strider mot det ni har registrerat som fakta. Källan bör rättas så att svaren blir korrekta.',
            'steps' => [
                'Hitta sidan svaret bygger på: :source.',
                'Rätta sidan om den är er egen, eller be utgivaren rätta den.',
            ],
        ],
    ],
    'evidence' => [
        'source_gap' => ':domain citerades i :gap_responses svar där konkurrenter nämndes och ni inte, de senaste :days dagarna. :competitor nämndes i :competitor_answers av dem.',
        'source_gap_without_competitor' => ':domain citerades i :gap_responses svar där konkurrenter nämndes och ni inte, de senaste :days dagarna.',
        'owned_content' => 'Inga egna sidor citeras på :prompts_without av :prompts_total promptar om ämnet ”:topic”. Synligheten där är :visibility %.',
        'new_competitor' => ':name nämns i :share % av svaren i :market de senaste :days dagarna men är inte registrerad som konkurrent.',
        'crawler_access' => ':bot har inte hämtat några sidor de senaste :days dagarna. Andra AI-robotar gjorde :other_hits förfrågningar under samma period.',
        'fact_correction' => 'Svar på :prompts promptar anger felaktig uppgift om :fact. Källan är :source.',
    ],
    'command' => [
        'queued' => 'Åtgärdsgenerering lagd i kön för :tenants tenanter.',
        'done' => 'Åtgärder genererade för :tenants tenanter.',
        'failed' => 'Åtgärdsgenereringen misslyckades för :failed av :tenants tenanter (id: :ids).',
    ],
];
