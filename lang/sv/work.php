<?php

// Meddelanden från ekko:work:recover (docs/spec.md 7.1, ADR 0032). Alla språk måste ha samma nycklar.

return [
    'recover' => [
        'done' => 'Publicerade :published väckningar för arbete som blev liggande.',
        'failed' => 'Publicerade :published väckningar; :failed kunde inte publiceras. Signalerna ligger kvar.',
    ],
];
