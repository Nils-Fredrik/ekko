<?php

// Rapport fra evalueringskommandoen for uttrekk (docs/spec.md 8.2, T20). Alle språk må ha de samme nøklene.

return [
    'eval' => [
        'responses' => 'Gullsett: :count svar (:languages).',
        'value' => 'Verdi',
        'stage' => 'Ledd',
        'precision' => 'Presisjon',
        'hit_rate' => 'Treffrate',
        'counts' => 'Riktige/spådd/forventet',
        'model' => 'modellens svar',
        'assembled' => 'lagret (sammenstilt)',
        'source_type' => 'source_type, :stage: :correct av :total riktige (:rate).',
        'source_type_miss' => 'Feil kildetype: :miss',
        'retried' => 'Enkeltforsøk: :count. Svar der modellleddet feilet: :failed.',
        'usage' => 'Tokens: :input inn, :output ut. Kostnad: :cost USD.',
        'waiting' => 'Venter på at batch :batch skal bli ferdig.',
        'no_key' => 'ANTHROPIC_API_KEY er ikke satt. Sett den, eller spill av en opptaksfil med --recorded.',
        'bad_file' => 'Gullsettet eller opptaksfilen mangler eller er ugyldig.',
    ],
];
