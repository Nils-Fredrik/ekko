<?php

// Validation messages for regex aliases (docs/spec.md 8.1, ADR 0010). All locales must have the same keys.

return [
    'regex' => [
        'too_long' => 'Regulært uttrykk kan være maks 200 tegn.',
        'control_characters' => 'Regulært uttrykk kan ikke inneholde kontrolltegn eller ugyldig tekst.',
        'invalid' => 'Regulært uttrykk er ugyldig.',
        'unsafe' => 'Regulært uttrykk er ikke tillatt: gjentatte grupper inne i gjentatte grupper og spesialkommandoer kan gjøre søket tregt.',
        'matches_empty' => 'Regulært uttrykk kan ikke treffe tom tekst.',
        'backtracking' => 'Regulært uttrykk er for tungt å kjøre. Forenkle det.',
    ],
];
