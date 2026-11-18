<?php

declare(strict_types=1);

return [
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'sso' => [
        'failed' => 'Signing in with the external account failed. Please try again.',
        'not_invited' => 'This account does not have access to Ekko. Ask an administrator for an invitation.',
        'confirm_with' => 'Confirm with :provider',
        'confirm_hint' => 'Signed in with :provider? Then confirm with that account instead of a password.',
        'confirm_failed' => 'The confirmation failed. Use the account you sign in with and try again.',
    ],
    'invitation' => [
        'expired' => 'The invitation has expired. Ask for a new invitation.',
        'unverified' => 'Verify your email address before accepting the invitation.',
    ],
    'two_factor' => [
        'required' => 'Your account level requires two-factor authentication. Turn it on before you continue.',
    ],
    'email_changed' => [
        'subject' => 'The e-mail address of your Ekko account has changed',
        'greeting' => 'Hi :name,',
        'line' => 'The e-mail address of your Ekko account has been changed to :email. This address no longer receives e-mail about the account.',
        'not_you' => 'Was this not you? Contact your administrator at once, so the account can be locked.',
        'password_hint' => 'Enter your password to change the e-mail address.',
    ],
];
