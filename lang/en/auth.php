<?php

declare(strict_types=1);

return [
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'sso' => [
        'failed' => 'Signing in with the external account failed. Please try again.',
        'not_invited' => 'This account does not have access to Ekko. Ask an administrator for an invitation.',
    ],
    'invitation' => [
        'expired' => 'The invitation has expired. Ask for a new invitation.',
        'unverified' => 'Verify your email address before accepting the invitation.',
    ],
    'two_factor' => [
        'required' => 'Your account level requires two-factor authentication. Turn it on before you continue.',
    ],
];
