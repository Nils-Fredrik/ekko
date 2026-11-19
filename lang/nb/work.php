<?php

// Meldinger fra ekko:work:recover og ekko:work:retry (docs/spec.md 7.1, ADR 0032). Alle språk må ha de samme nøklene.

return [
    'recover' => [
        'done' => 'Publiserte :published vekkinger for arbeid som ble stående.',
        'failed' => 'Publiserte :published vekkinger; :failed kunne ikke publiseres. Signalene blir stående.',
    ],
    'retry' => [
        'done' => 'Arbeidssignalet er startet igjen og vekket.',
        'not_found' => 'Fant ikke noe stoppet arbeidssignal med den ID-en i den tenanten.',
    ],
];
