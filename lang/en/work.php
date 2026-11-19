<?php

// Messages from ekko:work:recover and ekko:work:retry (docs/spec.md 7.1, ADR 0032). All languages must have the same keys.

return [
    'recover' => [
        'done' => 'Published :published wake-ups for stranded work.',
        'failed' => 'Published :published wake-ups; :failed could not be published. The signals stay pending.',
    ],
    'retry' => [
        'done' => 'The work signal is re-armed and woken.',
        'not_found' => 'No stopped work signal with that id in that tenant.',
    ],
];
