<?php

// Meddelanden från ekko:work:recover och ekko:work:retry (docs/spec.md 7.1, ADR 0032). Alla språk måste ha samma nycklar.

return [
    'recover' => [
        'done' => 'Publicerade :published väckningar för arbete som blev liggande.',
        'failed' => 'Publicerade :published väckningar; :failed kunde inte publiceras. Signalerna ligger kvar.',
    ],
    'retry' => [
        'done' => 'Arbetssignalen är återaktiverad och väckt.',
        'not_found' => 'Hittade ingen stoppad arbetssignal med det id:t i den tenanten.',
    ],
];
