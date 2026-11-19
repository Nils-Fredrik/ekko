<?php

return [
    'errors' => [
        'empty' => 'Faktat får inte vara tomt.',
        'too_long' => 'Faktat får ha högst :max tecken.',
        'limit' => 'Ett varumärke får ha högst :max fakta.',
        'duplicate' => 'Detta faktum är redan registrerat för varumärket.',
    ],
    'eval' => [
        'cases' => ':count svar och :claims förväntade påståenden (:languages).',
        'bad_file' => 'Guldsetet saknas eller är ogiltigt.',
        'no_key' => 'ANTHROPIC_API_KEY saknas. Använd --recorded för att spela upp inspelad utdata.',
        'verdict' => 'Bedömning',
        'precision' => 'Precision',
        'hit_rate' => 'Träffgrad',
        'counts' => 'Rätt/förutspått/förväntat',
        'accuracy' => 'Träffsäkerhet: :correct av :total förväntade bedömningar är rätt (:rate).',
        'false_claims' => 'Påståenden om fakta utan facit: :count.',
        'by_language' => 'Träffsäkerhet per språk: :languages.',
        'failed' => 'Svar utan användbart resultat: :failed.',
        'usage' => 'Förbrukning: :input tokens in, :output tokens ut, kostnad :cost USD.',
    ],
];
