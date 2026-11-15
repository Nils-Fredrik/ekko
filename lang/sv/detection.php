<?php

// Validation messages for regex aliases (docs/spec.md 8.1, ADR 0010). All locales must have the same keys.

return [
    'regex' => [
        'empty' => 'Det reguljära uttrycket får inte vara tomt.',
        'too_long' => 'Det reguljära uttrycket får vara högst 200 tecken.',
        'control_characters' => 'Det reguljära uttrycket får inte innehålla styrtecken eller ogiltig text.',
        'invalid' => 'Det reguljära uttrycket är ogiltigt.',
        'unsafe' => 'Det reguljära uttrycket är inte tillåtet: upprepade grupper inuti upprepade grupper och specialkommandon kan göra sökningen långsam.',
        'matches_empty' => 'Det reguljära uttrycket får inte matcha tom text.',
        'backtracking' => 'Det reguljära uttrycket är för tungt att köra. Förenkla det.',
    ],
];
