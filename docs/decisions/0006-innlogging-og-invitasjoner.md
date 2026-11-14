# 0006 – Innlogging med Google og Microsoft, invitasjoner og tofaktor

- **Status:** Akseptert
- **Dato:** 2026-11-14
- **Board:** `task:6` / T07

## Kontekst

`docs/spec.md` 5.4 sier at Google- og Microsoft-kontoer kobles til eksisterende inviterte brukere, at invitasjoner utløper etter 7 dager, og at plattformadmin og partner-rådgivere må bruke 2FA. Registrering er avslått, så det finnes ingen vei inn for en ny bruker uten en invitasjon. Claude-reviewen av T05 (finding #140) pekte også på at `User` ikke var `MustVerifyEmail`: en bruker kunne endre sin egen e-postadresse til en invitert adresse og godta en lekket invitasjonslenke.

## Beslutning

1. **Socialite** (Google innebygd, Microsoft via `socialiteproviders/microsoft`) på `/auth/{provider}/redirect` og `/callback` (`social.redirect`, `social.callback`), bare for gjester og med begrensningen `sso` (10 per minutt per IP). Nøkler kommer fra miljøet (`GOOGLE_*`, `MICROSOFT_*`). En leverandør uten nøkler gir 404 og vises ikke på innloggingssiden.
2. **Hvem slipper inn** (`App\Actions\Social\ResolveSocialUser`), i denne rekkefølgen:
   1. kontoen er allerede koblet (`social_accounts`, unik på leverandør og leverandør-ID), ellers
   2. kontoens verifiserte e-postadresse tilhører en eksisterende bruker: kontoen kobles, ellers
   3. adressen har en **ikke-utløpt** invitasjon: brukeren opprettes (uten passord, uten personlig tenant) og kobles. Invitasjonen godtas fortsatt med lenken i e-posten.
   4. ellers: ingen tilgang, ingen bruker, ingen kobling. Svaret er det samme for alle uinviterte kontoer.
   - Google må si `email_verified`. Microsoft returnerer postkassen (`mail`, ellers UPN) til en konto katalogen har verifisert. Sett `MICROSOFT_TENANT_ID` for å begrense til én katalog (standard er `common`).
   - Maks én konto per leverandør per bruker.
3. **Innlogging via SSO og 2FA:** har brukeren bekreftet 2FA, sendes hen til samme utfordring som ved passordinnlogging (`login.id` i sesjonen). SSO omgår aldri 2FA.
4. **Invitasjoner utløper etter 7 dager** (`config('ekko.invitation_ttl_days')`), beregnet fra `created_at` på serveren. Utløpte invitasjoner kan ikke godtas (lenken sletter dem og viser en melding), gir ikke SSO-tilgang, og blokkerer ikke en ny invitasjon til samme adresse (`InviteTeamMember` sletter dem først, fordi Jetstream ellers avviser en duplikat).
5. **Finding #140:** `User` implementerer `MustVerifyEmail` og `Features::emailVerification()` er på.
   - Å endre e-postadressen nullstiller verifiseringen og sender en ny bekreftelse til den nye adressen. Den som endrer til en annens adresse kan ikke bekrefte den.
   - `verified`-middleware (Jetstreams godta-rute har den) holder ubekreftede brukere ute, og `TeamInvitationController::accept` krever i tillegg selv en bekreftet adresse, så sjekken ikke avhenger av rutens middleware.
   - SSO regnes som bekreftelse, fordi leverandøren har verifisert adressen.
6. **2FA påkrevd** for plattformadmin og alle med rollen partner-rådgiver i en tenant (`User::requiresTwoFactor()`). Middleware `EnsureTwoFactorEnabled` ligger i `web`-gruppen og på API-ruten. Til 2FA er bekreftet (`two_factor_confirmed_at`) kommer brukeren bare til sidene i `config('ekko.two_factor_exempt_routes')` (profil, 2FA, passordbekreftelse, utlogging, e-postbekreftelse), og får ellers en omdirigering til profilsiden, eller 403 for JSON. 2FA er valgfritt for øvrige roller.
7. **Innloggingsbegrensning:** Fortifys `login`-begrenser (5 per minutt per e-post og IP) og `two-factor`-begrenseren, begge dekket av tester, pluss `sso` over.

## Konsekvenser

- `UserFactory::platformAdmin()` gir bekreftet 2FA som standard (`platformAdmin(false)` for det motsatte), og `withTwoFactor()` finnes for partner-rådgivere i tester.
- Nye ruter må ha 2FA-sjekken: den ligger i `web`-gruppen, og API-ruter med brukerautentisering bruker aliaset `two-factor.required`.
- En bruker opprettet via SSO har ingen passord før hen ber om tilbakestilling, og ingen personlig tenant før invitasjonen er godtatt (støttet av ADR 0004 punkt 10).
- Ikke gjort her: planlagt opprydding av utløpte invitasjoner (de er ufarlige og slettes når de brukes eller erstattes) og e-postvarsel ved utløp.
