<?php

// Ekko's own texts. All locales must have the same keys (tests/Unit/TranslationParityTest.php).

return [
    'name' => 'Ekko',
    'tagline' => 'Bevakning av varumärken i AI-sök',
    'locales' => [
        'nb' => 'Norsk bokmål',
        'sv' => 'Svenska',
        'en' => 'English',
    ],
    'acting_as' => [
        'banner' => 'Du agerar som :tenant.',
        'reason' => 'Orsak: :reason',
        'stop' => 'Avsluta',
    ],
    'account' => [
        'owns_tenants' => 'Du äger en tenant och kan inte radera ditt konto. Be plattformsadministratören att först överföra eller stänga tenanten.',
    ],
];
