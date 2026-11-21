<?php

// Decisions on unknown brands and the competitor setup API (docs/ux-arbeidsflate.md 6.3).
return [
    'errors' => [
        'not_found' => 'Navnet finnes ikke blant de ukjente merkevarene de siste :days dagene.',
        'known' => 'Navnet er allerede registrert som merkevare, konkurrent eller alias.',
        'decided' => 'Navnet er allerede behandlet.',
        'target' => 'Velg merkevaren eller en av konkurrentene.',
        'undo_expired' => 'Beslutningen kan ikke angres etter :hours timer. Arkiver konkurrenten eller fjern aliaset i stedet.',
        'duplicate' => 'Finnes allerede.',
        'reorder' => 'Rekkefølgen må ha med alle aktive konkurrenter én gang.',
    ],
];
