# 0004 – Roller, partner-tilgang og «opptre som tenant»

- **Status:** Akseptert
- **Dato:** 2026-11-14
- **Board:** `task:4` / T05

## Kontekst

`docs/spec.md` 5.2 beskriver fem roller og sier at partner-tilgang skal løses i en sentral `TenantAccess`-tjeneste som policyene bruker. Noen detaljer står ikke i spesifikasjonen: hvilke rettigheter en partner-rådgiver har i partnerens egen tenant, hvem som får tilgang til kunde-tenantene, hva som skjer med stengte tenants, og hvordan plattformadmin ser tenant-data.

## Beslutning

1. **Roller og rettigheter** ligger i `App\Enums\TenantRole` og `TenantPermission` og registreres som Jetstream-roller med oversatte navn (`lang/*/roles.php`). Teameieren regnes som tenant-admin. API-tokens har fortsatt bare `read` og `write` (15.1).
2. **`App\Tenancy\TenantAccess`** er eneste kilde for rollen en bruker har i en tenant. Rollen blir:
   1. tenant-admin for plattformadmin som opptrer som tenanten, ellers
   2. den sterkeste av medlemsrollen og partner-rådgiver via partnerens tenant. En partner-rådgiver er «som tenant-admin i kunde-tenantene» (5.2), så en lavere medlemsrolle i en kunde-tenant kan ikke begrense det.

   Policyene, `SetCurrentTenant`, tenant-velgeren og RLS (`app.tenant_ids`) bruker den. Jetstreams egne rolleoppslag (`User::teamRole()` og `hasTeamPermission()`) kjenner ikke til partner og «opptre som» og skal ikke brukes til tilgang. Bruk policyene.
3. **Partner-rådgiver** er en rolle i partnerens tenant. Den gir tenant-admin-rettigheter i partnerens tenant og i alle kunde-tenantene. Bare denne rollen når kunde-tenantene. En admin, redaktør eller leser i partnerens tenant ser bare partnerens tenant.
4. **Plattform-rettigheter** er ikke tenant-rettigheter. Bare plattformadmin kan opprette, slette og stenge tenants, endre pakke og opptre som tenant. Derfor kan verken tenant-admin eller partner-rådgiver slette en tenant eller endre pakken (5.2). En bruker som eier en tenant, kan heller ikke slette kontoen sin, fordi Jetstream da sletter tenantene brukeren eier. Plattformadmin må først overføre eller slette tenanten. En stengt tenant blokkerer også, så den ikke slettes ved et uhell.
5. **Plattformadmin ser tenant-data bare mens hen opptrer som tenanten.** Det finnes ingen `Gate::before` som gir plattformadmin alt. Tilgang til en tenants data går alltid via «opptre som», som logges og vises som banner. Admin-sider på tvers av tenants (T30) bruker tilkoblingen `pgsql_admin`.
6. **«Opptre som tenant»:**
   - Krever nylig bekreftet passord (`password.confirm`) og en årsak på 10–500 tegn.
   - Tilstanden ligger i sesjonen, ikke på brukeren. Den gjelder bare for plattformadmin som startet den, og ignoreres ellers.
   - `current_team_id` endres ikke.
   - Start og stopp logges som strukturerte loggposter (`acting_as.started` og `acting_as.stopped` med `user_id`, `tenant_id` og årsak).
   - Revisjonsloggen i activitylog kommer i `task:44`, etter T08.
7. **Rolletildeling:** Bare en partner-rådgiver, eller plattformadmin som opptrer som tenanten, kan gi rollen partner-rådgiver, fordi den gir tilgang til kunde-tenants. Andre roller følger `ManageMembers`.
   - Rollen kan ikke gis i en kunde-tenant, fordi det maks er ett nivå (5.1).
   - Sjekken gjøres når invitasjonen sendes. Jetstream godtar invitasjonen på vegne av eieren, så en gyldig invitasjon sjekkes ikke på nytt. Er tenanten blitt kunde-tenant etter at invitasjonen ble sendt, avvises den likevel.
8. **Stengte tenants** (`status = closed`) er ikke tilgjengelige for medlemmer eller partner, og vises ikke i tenant-velgeren. Plattformadmin kan opptre som dem, for eksempel for eksport før sletting (16). Pausede tenants er fortsatt tilgjengelige.
9. **RLS for partner:** Når en partner-rådgiver jobber i partnerens tenant, inneholder `app.tenant_ids` partnerens tenant og alle kunde-tenants som ikke er stengt. Det trengs for partneroversikten (T35). I en kunde-tenant inneholder den bare kunde-tenanten. Lag 1 (Eloquent-scopet) gjelder fortsatt bare den valgte tenanten.
10. **Tenant-velgeren** er Jetstreams team-velger. `User::allTeams()` og `User::switchTeam()` bruker `TenantAccess`, så kunde-tenants vises og kan velges. Valget lagres i `current_team_id` (5.4).
    - Velgeren viser bare tenants brukeren kan velge. Både velgeren og `switchTeam()` filtrerer på `canSelect()`, så de kan aldri skille lag.
    - Brukere uten personlig team (inviterte brukere) støttes.
    - Mens plattformadmin opptrer som en tenant, viser navigasjonen og lenken til teaminnstillinger den tenanten, uten at `current_team_id` endres.
    - Tenanten plattformadmin opptrer som, vises ikke i velgeren og kan ikke lagres som `current_team_id` (`TenantAccess::canSelect()`), fordi «opptre som» bare skal ligge i sesjonen.
11. **`SetCurrentTenant`** tømmer konteksten og rollebufferen i starten av hver forespørsel, så en tenant aldri overføres der containeren gjenbrukes.
12. **Policy for tenant-eide ressurser:** `App\Policies\TenantResourcePolicy` er basisklassen. En underklasse angir rettigheten for endring (`$manage`), og lesing krever `ViewData`. Policyene for T08-modellene kommer i `task:44`.

## Konsekvenser

- Nye tenant-eide modeller får en policy som arver `TenantResourcePolicy`.
- 2FA for plattformadmin og partner-rådgiver kommer i T07.
- `POST /admin/acting-as/{team}` krever bekreftet passord. Admin-UI-et (T30) bør bruke Jetstreams `ConfirmsPassword`-modal før skjemaet sendes, fordi omdirigeringen etter bekreftelse er en GET.
- Jetstreams standardtester for opprettelse og sletting av team, medlemmer og API-rettigheter er skrevet om til reglene i 5.2.
