<?php

// Mätvärdesnamn (docs/spec.md 9), markörer (7.5) och meddelanden från ekko:aggregate (T21). Alla språk måste ha samma nycklar.

return [
    'names' => [
        'visibility' => 'Synlighet',
        'recommendation_rate' => 'Rekommendationsgrad',
        'share_of_voice' => 'Share of voice',
        'average_position' => 'Genomsnittlig position',
        'first_place_rate' => 'Förstaplatsgrad',
        'citation_rate' => 'Citeringsgrad',
        'sentiment' => 'Sentiment',
        'stability' => 'Stabilitet',
        'source_share' => 'Källandel',
        'source_gap' => 'Källglapp',
        'bot_hits' => 'AI-robottreffar',
        'ai_traffic' => 'AI-trafik',
    ],
    'low_sample' => 'Få svar (n < :min)',
    'model_change' => 'Modellbyte: :engine gick från :from till :to',
    'aggregate' => [
        'queued' => 'Aggregeringen är lagd i kön för :tenants tenanter.',
        'done' => 'Aggregeringen är klar för :tenants tenanter.',
        'bad_date' => 'Ogiltigt datum eller datumintervall. Använd formatet ÅÅÅÅ-MM-DD.',
        'failed' => 'Aggregeringen misslyckades för :failed av :tenants tenanter (id :ids). De andra är klara.',
    ],
];
