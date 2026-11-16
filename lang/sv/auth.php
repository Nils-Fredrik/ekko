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
    'email_changed' => [
        'subject' => 'E-postadressen för ditt Ekko-konto har ändrats',
        'greeting' => 'Hej :name,',
        'line' => 'E-postadressen för ditt Ekko-konto har ändrats till :email. Den här adressen får inte längre e-post om kontot.',
        'not_you' => 'Var det inte du? Kontakta din administratör direkt så att kontot kan spärras.',
        'password_hint' => 'Ange ditt lösenord för att ändra e-postadressen.',
    ],
];
