<?php

// Ekko's own texts. All locales must have the same keys (tests/Unit/TranslationParityTest.php).

return [
    'name' => 'Ekko',
    'tagline' => 'Overvåking av merkevarer i AI-søk',
    'locales' => [
        'nb' => 'Norsk bokmål',
        'sv' => 'Svenska',
        'en' => 'English',
    ],
    'common' => [
        'close' => 'Lukk',
    ],
    'nav' => [
        'menu' => 'Meny',
        'open_menu' => 'Åpne meny',
        'theme' => 'Tema',
        'language' => 'Språk',
        'themes' => [
            'dark' => 'Mørkt',
            'light' => 'Lyst',
            'system' => 'Følg systemet',
        ],
    ],
    'acting_as' => [
        'banner' => 'Du opptrer som :tenant.',
        'reason' => 'Årsak: :reason',
        'stop' => 'Avslutt',
    ],
    'account' => [
        'owns_tenants' => 'Du eier en tenant og kan ikke slette kontoen din. Be plattformadministratoren om å overføre eller slette tenanten først.',
    ],
];
