# 0005 – Isolasjonstestsuite med ruteregister

- **Status:** Akseptert
- **Dato:** 2026-11-14
- **Board:** `task:5` / T06

## Kontekst

`docs/spec.md` 5.3 og T06 krever en testsuite som automatisk går gjennom alle ruter, API-endepunkter og MCP-tools og verifiserer isolasjon. En ny rute uten policy skal få CI til å feile, og testene kan ikke markeres som hoppet over.

En policy kan ikke leses ut av ruten selv: autorisasjon skjer i kontrollere, policyer og Jetstream-handlinger. Suiten må derfor vite hva hver rute skal tillate.

## Beslutning

1. **Ruteregister** i `tests/Feature/TenantIsolation/routes.php`. Hver rute har en tilgangsklasse, med rutenavn som nøkkel, eller «METODE uri» for ruter uten navn. Klassene er:
   - `public`: ingen innlogging
   - `guest`: innlogging og tilbakestilling av passord
   - `user`: brukerens egne data
   - `tenant`: tenant-data
   - `platformAdmin`: bare plattformadmin
2. **Dekning:** `RouteCoverageTest` feiler for ruter uten oppføring og for oppføringer uten rute. Den feiler også for ruter med parameter som ikke sier hvordan en annen tenants eller brukers ressurs testes (`foreign`), eller hvorfor det ikke gjelder (`withoutForeignCheck`). Dette er akseptkriteriet.
3. **Genererte tester** (`RouteIsolationTest`) bruker et scenario med tenant A og tenant B. Alt i B har en unik markør. Testene er:
   - Gjester får 401 eller 403 på alle ruter som krever innlogging.
   - Alle roller i tenant A (eier, admin, partner-rådgiver, redaktør og leser) får 403 eller 404 mot B sine ressurser, med alle HTTP-metoder, og et øyeblikksbilde av B er uendret etterpå. A er partner med en egen kunde-tenant, så partner-rådgiveren har lovlig tilgang på tvers av tenants, men ikke til B.
   - **Positiv kontroll:** Den lovlige forespørselen (`own`) lykkes for en rolle som har lov, eller for plattformadmin. Dermed betyr 403 eller 404 i fremmed-testen noe. `own` er påkrevd for tenant-ruter med parameter.
   - Roller i `deniedRoles` får 403 i egen tenant.
   - Plattformadmin-ruter avviser alle fem tenant-rollene.
   - Ingen innlogget GET-side svarer med 5xx eller inneholder markøren fra B, sett som eier, partner-rådgiver og leser.
   - URL-ene bygges strengt. En parameter ruten ikke har, eller en manglende parameter på en rute som krever innlogging, gir feil i stedet for en 404 som ser grønn ut. Ruter merket `signed()` får en gyldig signatur, så testen når autorisasjonen bak signaturen.
4. **Vern for kodebasen** (`CodebaseGuardsTest`):
   - Alle køede klasser i `app/` må arve `TenantJob`, som legger på `SetTenantContext` og `ApplyTenantRls`. `TenantAware` alene er ikke nok. Unntak må føres opp i `platform-jobs.php` med begrunnelse.
   - Køet mail og køede varsler kan ikke arve `TenantJob`, fordi de allerede arver `Mailable` og `Notification`. Den første oppgaven som innfører slike (T24 eller T32), lager en trait som setter `tenantId()` og de samme to middleware-ene, og utvider vernet til å godta den. De skal ikke føres opp i `platform-jobs.php`.
   - Modeller med en `tenant_id`-kolonne må bruke `BelongsToTenant`.
   - MCP-tools i `app/Mcp/Tools` må stå i `mcp-tools.php` (T34 fyller det ut og utvider de genererte testene).
   - Testfilene i isolasjonssuiten og `tests/Pest.php` kan ikke inneholde `skip()`, `skipOn…()`, `todo()`, `only()` eller PHPUnit-markører for å hoppe over. `phpunit.xml` kan ikke ekskludere grupper eller mapper.
5. **Avgrensning:** Testene for mekanismene ligger i `tests/Feature/Tenancy` (lag 1 og 2) og `tests/Feature/Access` (roller). `composer test:isolation` kjører alle tre mappene. `composer test` kjører dem også.
6. **Funn under arbeidet:**
   - Lagringsrutene for lokal disk (`storage/{path}`, GET og PUT) er slått av (`serve => false`). Ekko lagrer filer i GCS.
   - Passkeys er slått av i Fortify. De står ikke i 5.4, og alle passkey-rutene ga 500.
   - Jetstreams `api-tokens.destroy` ga 500 for en annen brukers token. Den gir nå 404 (`App\Http\Controllers\Jetstream\ApiTokenController`).
   - Jetstream lot hvem som helst med en gyldig signert invitasjonslenke godta invitasjonen på vegne av mottakeren. Brukeren som klikket, ble ikke medlem, men mottakeren ble lagt til i tenanten. Nå kan bare den inviterte brukeren godta (`App\Http\Controllers\Jetstream\TeamInvitationController`).

## Konsekvenser

- Den som legger til en rute eller et MCP-tool, må føre det inn i registeret.
- CI (T10) må kjøre `composer test` som påkrevd sjekk. Isolasjonstestene er en del av den.
- Når tenant-eide ressurser får egne ruter (T26–T33), skal oppføringen deres ha `foreign` mot en ressurs i tenant B.
