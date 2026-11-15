<?php

declare(strict_types=1);

return [
    'failed' => 'Dessa uppgifter stämmer inte överens med vårt register.',
    'password' => 'Lösenordet är fel.',
    'throttle' => 'För många inloggningsförsök. Var vänlig försök igen om :seconds sekunder.',
    'sso' => [
        'failed' => 'Inloggningen med det externa kontot misslyckades. Försök igen.',
        'not_invited' => 'Det här kontot har inte tillgång till Ekko. Be en administratör om en inbjudan.',
    ],
    'invitation' => [
        'expired' => 'Inbjudan har gått ut. Be om en ny inbjudan.',
        'unverified' => 'Bekräfta din e-postadress innan du accepterar inbjudan.',
    ],
    'two_factor' => [
        'required' => 'Din kontonivå kräver tvåfaktorsautentisering. Aktivera den innan du fortsätter.',
    ],
];
