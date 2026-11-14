<?php

// Tenant roles (docs/spec.md 5.2). All locales must have the same keys.

return [
    'admin' => [
        'name' => 'Tenant-admin',
        'description' => 'Inviterer og fjerner brukere, redigerer merkevarer, markeder, prompts og konkurrenter, setter varsler og rapportmottakere og ser forbruk.',
    ],
    'partner_advisor' => [
        'name' => 'Partner-rådgiver',
        'description' => 'Som tenant-admin i partnerens egen tenant og i alle kunde-tenantene. Kan ikke slette tenants eller endre pakke.',
    ],
    'editor' => [
        'name' => 'Redaktør',
        'description' => 'Redigerer prompts og konkurrenter og kjører manuelle kontroller innenfor kvoten.',
    ],
    'reader' => [
        'name' => 'Leser',
        'description' => 'Ser dashboard, svar, kilder og rapporter.',
    ],
];
