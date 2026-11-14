<?php

// Tenant roles (docs/spec.md 5.2). All locales must have the same keys.

return [
    'admin' => [
        'name' => 'Tenant admin',
        'description' => 'Invites and removes users, edits brands, markets, prompts and competitors, sets alerts and report recipients, and sees usage.',
    ],
    'partner_advisor' => [
        'name' => 'Partner advisor',
        'description' => 'Tenant admin in the partner\'s own tenant and in all client tenants. Cannot delete tenants or change the plan.',
    ],
    'editor' => [
        'name' => 'Editor',
        'description' => 'Edits prompts and competitors and runs manual checks within the quota.',
    ],
    'reader' => [
        'name' => 'Reader',
        'description' => 'Sees the dashboard, answers, sources and reports.',
    ],
];
