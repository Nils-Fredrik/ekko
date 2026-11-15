<?php

// Ekko's own texts. All locales must have the same keys (tests/Unit/TranslationParityTest.php).

return [
    'name' => 'Ekko',
    'tagline' => 'Brand monitoring in AI search',
    'locales' => [
        'nb' => 'Norsk bokmål',
        'sv' => 'Svenska',
        'en' => 'English',
    ],
    'common' => [
        'close' => 'Close',
    ],
    'nav' => [
        'menu' => 'Menu',
        'open_menu' => 'Open menu',
        'theme' => 'Theme',
        'language' => 'Language',
        'themes' => [
            'dark' => 'Dark',
            'light' => 'Light',
            'system' => 'Follow system',
        ],
    ],
    'acting_as' => [
        'banner' => 'You are acting as :tenant.',
        'reason' => 'Reason: :reason',
        'stop' => 'Stop',
    ],
    'account' => [
        'owns_tenants' => 'You own a tenant and cannot delete your account. Ask the platform admin to transfer or delete the tenant first.',
    ],
];
