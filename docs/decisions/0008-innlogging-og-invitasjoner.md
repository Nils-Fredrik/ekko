# 0008 – Innlogging med Google, invitasjoner og tofaktor

- **Status:** Akseptert
- **Dato:** 2026-11-14
- **Board:** `task:6` / T07

## Kontekst

`docs/spec.md` 5.4 sier at Google- og Microsoft-kontoer kobles til eksisterende inviterte brukere, at invitasjoner utløper etter 7 dager, og at plattformadmin og partner-rådgivere må bruke 2FA. Registrering er avslått, så det finnes ingen vei inn for en ny bruker uten en invitasjon. Claude-reviewen av T05 (finding #140) pekte også på at `User` ikke var `MustVerifyEmail`: en bruker kunne endre sin egen e-postadresse til en invitert adresse og godta en lekket invitasjonslenke.

Eieren besluttet 2026-11-14 at Microsoft-innlogging ikke trengs i denne runden.

## Beslutning

0. **Bare Google nå.** Microsoft er tatt ut (pakke, konfigurasjon, knapp og ruter). `/auth/microsoft/*` gir 404. Se «Microsoft senere» nederst for hva som må på plass før den kommer tilbake.
1. **Socialite** (Google, innebygd) på `/auth/{provider}/redirect` og `/callback` (`social.redirect`, `social.callback`), der `{provider}` er begrenset til `config('ekko.sso_providers')`. Bare for gjester og med begrensningen `sso` (10 per minutt per IP). Nøkler kommer fra miljøet (`GOOGLE_*`). En leverandør uten nøkler gir 404 og vises ikke på innloggingssiden.
2. **Hvem slipper inn** (`App\Actions\Social\ResolveSocialUser`), i denne rekkefølgen:
   1. kontoen er allerede koblet (`social_accounts`, unik på leverandør og leverandør-ID), ellers
   2. leverandøren har verifisert e-postadressen **og** den tilhører en eksisterende bruker som selv har en bekreftet adresse: kontoen kobles. En bruker med ubekreftet adresse avvises uten kobling og uten at noe endres (finding #140: adressen kan være skrevet inn av en annen), ellers
   3. leverandøren har verifisert adressen og den har en **ikke-utløpt** invitasjon: brukeren opprettes (uten passord) og kobles, og invitasjonene godtas (punkt 3a).
   4. ellers: ingen tilgang, ingen bruker, ingen kobling.
   - Avslag skjer før noe skrives: «allerede koblet hos denne leverandøren» sjekkes før brukeren endres.
   - Hvilken adresse leverandøren har verifisert avgjøres ett sted (`SocialLoginController::verifiedEmail`, som returnerer adressen eller null): Google `getEmail()` når `email_verified` er sann. En annen leverandør gir null til den har sin egen regel der, og virker da bare for kontoer som allerede er koblet.
   - Maks én konto per leverandør per bruker.
3. **Innlogging via SSO og 2FA:** har brukeren bekreftet 2FA, sendes hen til samme utfordring som ved passordinnlogging (`login.id` i sesjonen). SSO omgår aldri 2FA.
3a. **Invitasjoner godtas ved opprettelse via SSO.** Innloggingen har bevist at brukeren eier adressen, så alle aktive (ikke-utløpte) invitasjoner til den godtas i samme transaksjon gjennom `AddsTeamMembers` (samme handling som Jetstream bruker, med eierens rettigheter), og slettes. Blir ingen godtatt (stengt tenant, partner-rolle i en kunde-tenant), rulles alt tilbake: ingen bruker, ingen kobling. Den første tenanten blir `current_team_id`. En invitasjon som ikke lenger er gyldig (for eksempel partner-rolle i en tenant som er blitt kunde-tenant) hoppes over og blir liggende. `TeamInvitationController::accept` bytter også til tenanten når brukeren ikke har en aktiv tenant.
3b. **E-postadresser lagres med små bokstaver og er unike uavhengig av skrift.** `User` har en mutator som trimmer og gjør om til små bokstaver, en migrasjon gjør om eksisterende adresser og legger unik indeks på `lower(email)` (den stanser med feilmelding hvis to brukere bare skiller seg i skrift, og slår aldri sammen), og `App\Rules\UniqueEmail` gir valideringsfeil i profil og opprettelse. Dermed kan en invitasjon aldri havne på en tilsynelatende lik bruker. Invitasjoner lagres med små bokstaver (`InviteTeamMember`), og `AddTeamMember`, `Team::hasUserWithEmail` og slettingen av utløpte invitasjoner sammenligner med `lower(email)` (for gamle invitasjoner med blandet skrift), så en invitasjon med blandet skrift godtas etter både SSO og passordinnlogging. Etter SSO som oppretter bruker og godtar invitasjonen, fjernes invitasjonslenken som `url.intended`, fordi den nå er slettet.
4. **Invitasjoner utløper etter 7 dager** (`config('ekko.invitation_ttl_days')`), beregnet fra `created_at` på serveren. Utløpte invitasjoner kan ikke godtas (lenken sletter dem og viser en melding), gir ikke SSO-tilgang, og blokkerer ikke en ny invitasjon til samme adresse (`InviteTeamMember` sletter dem først, fordi Jetstream ellers avviser en duplikat).
5. **Finding #140:** `User` implementerer `MustVerifyEmail` og `Features::emailVerification()` er på.
   - Å endre e-postadressen nullstiller verifiseringen og sender en ny bekreftelse til den nye adressen. Den som endrer til en annens adresse kan ikke bekrefte den.
   - `verified`-middleware (Jetstreams godta-rute har den) holder ubekreftede brukere ute, og `TeamInvitationController::accept` krever i tillegg selv en bekreftet adresse, så sjekken ikke avhenger av rutens middleware.
   - SSO regnes som bekreftelse, fordi leverandøren har verifisert adressen.
6. **2FA påkrevd** for plattformadmin og alle med rollen partner-rådgiver i en tenant (`User::requiresTwoFactor()`). Middleware `EnsureTwoFactorEnabled` ligger i `web`-gruppen og på API-ruten. Til 2FA er bekreftet (`two_factor_confirmed_at`) kommer brukeren bare til sidene i `config('ekko.two_factor_exempt_routes')` (profil, 2FA, passordbekreftelse, utlogging, e-postbekreftelse), og får ellers en omdirigering til profilsiden, eller 403 for JSON. 2FA er valgfritt for øvrige roller.
   - Tillegg 2026-11-18 (T07-F1): mens eieren tester før lansering kan kravet slås av med `EKKO_REQUIRE_TWO_FACTOR=false` (Terraform `require_two_factor`). Da gir `requiresTwoFactor()` `false` for alle. Bare en eksplisitt `false` slår det av, og en tom verdi beholder kravet. Oppsatt 2FA blir fortsatt spurt etter ved innlogging. Kravet slås på igjen før T40 (spec 5.4, runbook).
7. **Innloggingsbegrensning:** Fortifys `login`-begrenser (5 per minutt per e-post og IP) og `two-factor`-begrenseren, begge dekket av tester, pluss `sso` over.

## Konsekvenser

- `UserFactory::platformAdmin()` gir bekreftet 2FA som standard (`platformAdmin(false)` for det motsatte), og `withTwoFactor()` finnes for partner-rådgivere i tester.
- Nye ruter må ha 2FA-sjekken: den ligger i `web`-gruppen, og API-ruter med brukerautentisering bruker aliaset `two-factor.required`.
- En bruker opprettet via SSO har ingen passord før hen ber om tilbakestilling, og ingen personlig tenant før invitasjonen er godtatt (støttet av ADR 0004 punkt 10).
- **Kjent begrensning:** en bruker opprettet via SSO har et tilfeldig passord, men 2FA-oppsett krever passordbekreftelse (`confirmPassword`). Brukeren må først sette et passord via «glemt passord». Det gjelder særlig partner-rådgivere, som må ha 2FA. Ingen egen flyt bygges nå.
- Utløpte invitasjoner slettes før valideringen i `InviteTeamMember`, også når valideringen siden feiler. Det er ufarlig.
- Ikke gjort her: planlagt opprydding av utløpte invitasjoner (de er ufarlige og slettes når de brukes eller erstattes) og e-postvarsel ved utløp.

## Microsoft senere

Reviewen av PR #15 fant at Microsoft-innlogging med `common` åpner for kontoovertakelse («nOAuth»): `mail` og UPN kan en katalogadministrator sette fritt, så hvem som helst kan logge inn fra sin egen katalog med en annens adresse. Kommer Microsoft tilbake, gjelder dette:

- App-registreringen (A5) må sende det valgfrie claimet `xms_edov` i ID-tokenet.
- `verifiedEmail` gir bare claimet `email` fra det validerte ID-tokenet, og bare når `xms_edov` er sant. Aldri UPN eller Graph `mail` (som `getEmail()` i `socialiteproviders/microsoft` returnerer).
- Testene må dekke: uten `xms_edov` ingen kobling eller opprettelse, UPN lik et offer men annet claim gir ingenting, og en allerede koblet konto virker.
- Å binde til én katalog med `MICROSOFT_TENANT_ID` er en ekstra sperre, ikke en erstatning.

Koden for dette finnes i historikken til PR #15 (commit `1c365d6`).

## Tillegg 2026-11-18: bekreftelse med Google (T07-F2)

En bruker som er opprettet via Google, har et tilfeldig passord som ingen kjenner. Der appen krever bekreftet passord (slå på 2FA, «opptre som tenant», slette tenant), kan en bruker med koblet Google-konto bekrefte med Google i stedet.

- `GET /auth/google/confirm` (innlogget) lagrer en ventende bekreftelse for brukeren og leverandøren i økten, og sender til Google med `prompt=select_account` og `max_age=0`.
- Callbacken er den samme adressen som ved innlogging, fordi bare den er registrert hos Google. For en innlogget bruker gir den bare bekreftelse når økten har en ventende bekreftelse for den samme brukeren og leverandøren, og når Google returnerer nettopp den kontoen som er koblet til brukeren. Da settes `auth.password_confirmed_at`, som ved passord. Alt annet gir ingen bekreftelse.
- Begrensning: Socialite gir ikke ID-tokenet videre, så appen sjekker ikke `auth_time`. Det er Google som avgjør om brukeren må autentisere seg på nytt. Bekreftelsen viser at den som sitter ved maskinen, har kontroll over den koblede Google-kontoen nå. Det er en svakere sjekk enn et passord bare brukeren kjenner, men det er den samme kontoen brukeren logger inn med.
