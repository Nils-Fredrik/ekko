<?php

// Tiltak (docs/spec.md 11.3, T43): typer, statuser, effektnivåer, maltekster og evidenssetninger. Tallene i
// plassholderne kommer fra databasen. Alle språk må ha de samme nøklene.

return [
    'types' => [
        'source_gap' => 'Kildegap',
        'owned_content' => 'Eget innhold',
        'new_competitor' => 'Ny konkurrent',
        'crawler_access' => 'Robottilgang',
        'fact_correction' => 'Faktaretting',
        'own' => 'Eget tiltak',
    ],
    'statuses' => [
        'new' => 'Ny',
        'planned' => 'Planlagt',
        'in_progress' => 'Pågår',
        'done' => 'Fullført',
        'declined' => 'Avvist',
        'expired' => 'Utløpt',
    ],
    'impact_levels' => [
        1 => 'Svært lav',
        2 => 'Lav',
        3 => 'Middels',
        4 => 'Høy',
        5 => 'Svært høy',
    ],
    'templates' => [
        'source_gap' => [
            'title' => 'Bli omtalt på :domain',
            'description' => 'AI-tjenestene siterer :domain når de nevner konkurrentene deres, men ikke dere. Innhold om dere der kan gjøre at dere tas med i de samme svarene.',
            'steps' => [
                'Se hvilke sider på :domain som siteres, og hva de handler om.',
                'Kontakt redaksjonen eller eieren av :domain og foreslå innhold der dere kan omtales.',
                'Sørg for at dere er oppført med riktig og oppdatert informasjon der det er naturlig.',
            ],
        ],
        'owned_content' => [
            'title' => 'Lag egne sider om «:topic»',
            'description' => 'Dere er lite synlige på promptene om «:topic», og AI-tjenestene siterer aldri egne sider der. Sider som svarer direkte på spørsmålene, gir tjenestene noe å vise til.',
            'steps' => [
                'Gå gjennom promptene om «:topic» og finn spørsmålene dere ikke svarer på i dag.',
                'Skriv eller oppdater sider som svarer direkte og konkret på dem.',
                'Lenk sidene fra relevante steder på nettstedet og i sitemap.',
            ],
        ],
        'new_competitor' => [
            'title' => 'Vurder :name som konkurrent',
            'description' => ':name nevnes ofte i svarene i :market, men er ikke registrert som konkurrent. Registrert som konkurrent måles :name i share of voice og varsler.',
            'steps' => [
                'Se svarene der :name nevnes, og vurder om de konkurrerer om de samme kundene.',
                'Legg til :name som konkurrent med alias og domener hvis det er riktig.',
            ],
        ],
        'crawler_access' => [
            'title' => ':bot henter ikke sidene deres',
            'description' => ':bot har ikke hentet noen sider fra dere i perioden, selv om andre AI-roboter har gjort det. Da kan tjenesten mangle oppdatert innhold om dere.',
            'steps' => [
                'Sjekk at robots.txt ikke blokkerer :bot.',
                'Sjekk at brannmur, CDN eller botvern ikke stopper :bot.',
                'Sørg for at sitemap er oppdatert og tilgjengelig.',
            ],
        ],
        'fact_correction' => [
            'title' => 'Rett feil opplysning: :fact',
            'description' => 'AI-svar oppgir en opplysning som strider mot det dere har registrert som fakta. Kilden bør rettes, slik at svarene blir riktige.',
            'steps' => [
                ':find',
                ':fix',
            ],
        ],
    ],
    // Parts of the fact_correction texts that depend on where the wrong claim comes from (FactCorrectionSource).
    'fact' => [
        'find_known' => 'Finn siden svaret bygger på: :source.',
        'find_unknown' => 'Finn hvilken side svarene bygger på, for eksempel ved å søke etter påstanden i svarene.',
        'fix_own' => 'Rett deres egen side, slik at opplysningen stemmer.',
        'fix_other' => 'Be utgiveren av siden rette opplysningen.',
        'fix_unknown' => 'Rett siden hvis den er deres egen, ellers be utgiveren rette den.',
        'source_unknown' => 'ikke oppgitt i svarene',
    ],
    'evidence' => [
        'source_gap' => ':domain ble sitert i :gap_responses svar der konkurrenter ble nevnt og dere ikke, de siste :days dagene. :competitor ble nevnt i :competitor_answers av dem.',
        'source_gap_without_competitor' => ':domain ble sitert i :gap_responses svar der konkurrenter ble nevnt og dere ikke, de siste :days dagene.',
        'owned_content' => 'Ingen egne sider siteres på :prompts_without av :prompts_total prompts om temaet «:topic». Synligheten der er :visibility %.',
        'new_competitor' => ':name nevnes i :share % av svarene i :market de siste :days dagene, men er ikke registrert som konkurrent.',
        'crawler_access' => ':bot har ikke hentet noen sider de siste :days dagene. Andre AI-roboter gjorde :other_hits forespørsler i samme periode.',
        'fact_correction' => 'Svar på :prompts prompts oppgir feil opplysning om :fact. Kilden er :source.',
    ],
    'command' => [
        'queued' => 'Tiltaksgenerering lagt i køen for :tenants tenanter.',
        'done' => 'Tiltak generert for :tenants tenanter.',
        'failed' => 'Tiltaksgenerering feilet for :failed av :tenants tenanter (id: :ids).',
    ],
];
