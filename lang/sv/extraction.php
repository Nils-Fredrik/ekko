<?php

// Rapport från utvärderingskommandot för extrahering (docs/spec.md 8.2, T20). Alla språk måste ha samma nycklar.

return [
    'eval' => [
        'responses' => 'Guldset: :count svar (:languages).',
        'value' => 'Värde',
        'stage' => 'Steg',
        'precision' => 'Precision',
        'hit_rate' => 'Träffgrad',
        'counts' => 'Rätt/förutspått/förväntat',
        'model' => 'modellens svar',
        'assembled' => 'sparat (sammanställt)',
        'source_type' => 'source_type, :stage: :correct av :total rätt (:rate).',
        'source_type_miss' => 'Fel källtyp: :miss',
        'retried' => 'Enstaka omförsök: :count. Svar där modellsteget misslyckades: :failed.',
        'usage' => 'Tokens: :input in, :output ut. Kostnad: :cost USD.',
        'waiting' => 'Väntar på att batch :batch ska bli klar.',
        'no_key' => 'ANTHROPIC_API_KEY är inte satt. Sätt den, eller spela upp en inspelningsfil med --recorded.',
        'bad_file' => 'Guldsetet eller inspelningsfilen saknas eller är ogiltig.',
    ],
];
