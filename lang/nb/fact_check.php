<?php

return [
    'errors' => [
        'empty' => 'Faktaet kan ikke være tomt.',
        'too_long' => 'Faktaet kan ha høyst :max tegn.',
        'limit' => 'En merkevare kan ha høyst :max fakta.',
        'duplicate' => 'Dette faktaet er allerede registrert for merkevaren.',
    ],
    'eval' => [
        'cases' => ':count svar og :claims forventede påstander (:languages).',
        'bad_file' => 'Gullsettet mangler eller er ugyldig.',
        'no_key' => 'ANTHROPIC_API_KEY mangler. Bruk --recorded for å spille av innspilt utdata.',
        'waiting' => 'Venter på batch :batch.',
        'verdict' => 'Dom',
        'precision' => 'Presisjon',
        'hit_rate' => 'Treffrate',
        'counts' => 'Riktige/spådd/forventet',
        'accuracy' => 'Treffsikkerhet: :correct av :total forventede vurderinger er riktige (:rate).',
        'false_claims' => 'Påstander på fakta uten fasit: :count.',
        'by_language' => 'Treffsikkerhet per språk: :languages.',
        'failed' => 'Svar uten brukbart resultat: :failed.',
        'usage' => 'Forbruk: :input tokens inn, :output tokens ut, kostnad :cost USD.',
    ],
];
