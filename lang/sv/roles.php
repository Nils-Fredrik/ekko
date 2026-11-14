<?php

// Tenant roles (docs/spec.md 5.2). All locales must have the same keys.

return [
    'admin' => [
        'name' => 'Tenant-admin',
        'description' => 'Bjuder in och tar bort användare, redigerar varumärken, marknader, prompter och konkurrenter, ställer in larm och rapportmottagare och ser förbrukning.',
    ],
    'partner_advisor' => [
        'name' => 'Partnerrådgivare',
        'description' => 'Som tenant-admin i partnerns egen tenant och i alla kund-tenants. Kan inte radera tenants eller ändra paket.',
    ],
    'editor' => [
        'name' => 'Redaktör',
        'description' => 'Redigerar prompter och konkurrenter och kör manuella kontroller inom kvoten.',
    ],
    'reader' => [
        'name' => 'Läsare',
        'description' => 'Ser dashboard, svar, källor och rapporter.',
    ],
];
