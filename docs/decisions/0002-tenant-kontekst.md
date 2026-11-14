# 0002 – Tenant-kontekst og regler for tenant-eide modeller

- **Status:** Akseptert
- **Dato:** 2026-11-14
- **Board:** `task:2` / T03

## Kontekst

`docs/spec.md` 5.3 beskriver lag 1 av isolasjonen: traiten `BelongsToTenant`, `current_tenant_id()` og jobbmiddleware `SetTenantContext`. Spesifikasjonen sier ikke hvor konteksten lagres, hva som skjer når en modell skrives til en annen tenant enn konteksten, eller hvordan konteksten settes i web-forespørsler før `TenantAccess` (T05) og `ApplyTenantRls` (T04) finnes.

## Beslutning

1. **`App\Tenancy\TenantContext`** holder én tenant-ID og er registrert som `scoped` i containeren. Laravel nullstiller scoped-instanser mellom forespørsler og mellom jobber i køarbeideren, så en tenant kan ikke lekke videre. `current_tenant_id()` kaster `MissingTenantContextException` når ingen tenant er satt.
2. **Konteksten er én tenant.** Applikasjonslaget filtrerer på `tenant_id = current_tenant_id()`. En partner jobber i én kunde-tenant om gangen ved å bytte tenant. Listen over tillatte tenants (`app.tenant_ids`) hører til databaselaget (T04).
3. **Strenge skriveregler i `BelongsToTenant`**, i tillegg til spesifikasjonen:
   - `tenant_id` settes fra konteksten ved opprettelse. Er den satt til en annen tenant, kastes `TenantMismatchException`.
   - `tenant_id` kan aldri endres.
   - En modell som er lest i én tenant, kan ikke lagres eller slettes mens konteksten er en annen. Det hindrer at en jobb skriver til feil tenant.
4. **Jobber** arver `App\Jobs\TenantJob`, som tar `tenantId` i konstruktøren og har `SetTenantContext` som middleware. Middleware kjører jobben i jobbens tenant og setter tilbake forrige kontekst etterpå, også ved feil. En jobb uten tenant avvises.
5. **Web:** `SetCurrentTenant` setter brukerens nåværende team (Jetstream) i konteksten, men bare når brukeren er medlem. T05 utvider dette med partner-tilgang og «opptre som tenant».
6. **Tabeller:** `App\Tenancy\TenantSchema::tenantColumn($table)` lager `tenant_id` med fremmednøkkel til `teams.id` (`ON DELETE CASCADE`) og indeks. Alle tenant-eide tabeller (T08 og senere) skal bruke den.
7. **Tenant-kolonnene** som plattformadmin styrer (`partner_team_id`, `plan_id`, `status`, `byok_enabled`) og `users.is_platform_admin` kan ikke masse-tilordnes. `slack_webhook_url` er kryptert og sendes aldri til frontend, fordi Jetstream deler team-modellene med Vue.

## Konsekvenser

- Kode som leser tenant-eide data uten kontekst (kommandoer, seedere, admin), må bruke `TenantContext::run($tenantId, fn () => …)` eller eksplisitt `withoutGlobalScope(TenantScope::class)`. Det siste skal bare brukes av plattformadmin-kode og logges (T05).
- `withoutGlobalScope` omgår lag 1. Lag 2 (RLS i T04) stopper fortsatt lesing på tvers.
