<?php

// Meldinger fra ekko:work:recover (docs/spec.md 7.1, ADR 0032). Alle språk må ha de samme nøklene.

return [
    'recover' => [
        'done' => 'Publiserte :published vekkinger for arbeid som ble stående.',
        'failed' => 'Publiserte :published vekkinger; :failed kunne ikke publiseres. Signalene blir stående.',
    ],
];
