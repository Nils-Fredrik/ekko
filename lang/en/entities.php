<?php

// Decisions on unknown brands and the competitor setup API (docs/ux-arbeidsflate.md 6.3).
return [
    'errors' => [
        'not_found' => 'The name is not among the unknown brands of the last :days days.',
        'known' => 'The name is already registered as the brand, a competitor or an alias.',
        'decided' => 'The name has already been handled.',
        'target' => 'Choose the brand or one of the competitors.',
        'undo_expired' => 'The decision cannot be undone after :hours hours. Archive the competitor or remove the alias instead.',
        'duplicate' => 'Already exists.',
        'reorder' => 'The order must include every active competitor once.',
    ],
];
