<?php

// Metrikknavn (docs/spec.md 9), markører (7.5) og meldinger fra ekko:aggregate (T21). Alle språk må ha de samme nøklene.

return [
    'names' => [
        'visibility' => 'Synlighet',
        'recommendation_rate' => 'Anbefalingsrate',
        'share_of_voice' => 'Share of voice',
        'average_position' => 'Gjennomsnittlig posisjon',
        'first_place_rate' => 'Førsteplass-rate',
        'citation_rate' => 'Sitatrate',
        'sentiment' => 'Sentiment',
        'stability' => 'Stabilitet',
        'source_share' => 'Kildeandel',
        'source_gap' => 'Kildegap',
        'bot_hits' => 'AI-robottreff',
        'ai_traffic' => 'AI-trafikk',
    ],
    'low_sample' => 'Få svar (n < :min)',
    'model_change' => 'Modellbytte: :engine gikk fra :from til :to',
    'aggregate' => [
        'queued' => 'Aggregering lagt i køen for :tenants tenanter.',
        'done' => 'Aggregering ferdig for :tenants tenanter.',
        'bad_date' => 'Ugyldig dato eller datoperiode. Bruk formatet ÅÅÅÅ-MM-DD.',
        'failed' => 'Aggregeringen feilet for :failed av :tenants tenanter (id :ids). De andre er ferdige.',
    ],
];
