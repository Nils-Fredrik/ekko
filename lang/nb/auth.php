<?php

declare(strict_types=1);

return [
    'failed' => 'Disse opplysningene samsvarer ikke med hva vi har lagret.',
    'password' => 'Passordet er feil.',
    'throttle' => 'For mange innloggingsforsøk. Vennligst prøv igjen om :seconds sekunder.',
    'sso' => [
        'failed' => 'Innloggingen med den eksterne kontoen mislyktes. Prøv igjen.',
        'not_invited' => 'Denne kontoen har ikke tilgang til Ekko. Be en administrator om en invitasjon.',
        'confirm_with' => 'Bekreft med :provider',
        'confirm_hint' => 'Logget du inn med :provider? Da bekrefter du med den kontoen i stedet for passord.',
        'confirm_failed' => 'Bekreftelsen mislyktes. Bruk den samme kontoen som du logger inn med, og prøv igjen.',
    ],
    'invitation' => [
        'expired' => 'Invitasjonen er utløpt. Be om en ny invitasjon.',
        'unverified' => 'Bekreft e-postadressen din før du godtar invitasjonen.',
    ],
    'two_factor' => [
        'required' => 'Ditt kontonivå krever tofaktorautentisering. Slå den på før du fortsetter.',
    ],
    'email_changed' => [
        'subject' => 'E-postadressen på Ekko-kontoen din er endret',
        'greeting' => 'Hei :name,',
        'line' => 'E-postadressen på Ekko-kontoen din er endret til :email. Denne adressen får ikke lenger e-post om kontoen.',
        'not_you' => 'Var det ikke deg? Kontakt administratoren din med en gang, slik at kontoen kan sperres.',
        'password_hint' => 'Skriv inn passordet ditt for å endre e-postadressen.',
    ],
];
