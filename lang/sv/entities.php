<?php

// Decisions on unknown brands and the competitor setup API (docs/ux-arbeidsflate.md 6.3).
return [
    'errors' => [
        'not_found' => 'Namnet finns inte bland de okända varumärkena de senaste :days dagarna.',
        'known' => 'Namnet är redan registrerat som varumärke, konkurrent eller alias.',
        'decided' => 'Namnet är redan behandlat.',
        'target' => 'Välj varumärket eller en av konkurrenterna.',
        'undo_expired' => 'Beslutet kan inte ångras efter :hours timmar. Arkivera konkurrenten eller ta bort aliaset i stället.',
        'duplicate' => 'Finns redan.',
        'reorder' => 'Ordningen måste innehålla alla aktiva konkurrenter en gång.',
    ],
];
