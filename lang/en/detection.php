<?php

// Validation messages for regex aliases (docs/spec.md 8.1, ADR 0010). All locales must have the same keys.

return [
    'regex' => [
        'too_long' => 'The regular expression can be at most 200 characters.',
        'control_characters' => 'The regular expression cannot contain control characters or invalid text.',
        'invalid' => 'The regular expression is invalid.',
        'unsafe' => 'The regular expression is not allowed: repeated groups inside repeated groups and special commands can make the search slow.',
        'matches_empty' => 'The regular expression cannot match empty text.',
        'backtracking' => 'The regular expression is too heavy to run. Simplify it.',
    ],
];
