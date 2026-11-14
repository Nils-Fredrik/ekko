# 0003 – Rolle, transaksjon og migreringshjelper for row-level security

- **Status:** Akseptert
- **Dato:** 2026-11-14
- **Board:** `task:3` / T04

## Kontekst

`docs/spec.md` 5.3 beskriver lag 2 av isolasjonen: RLS på alle tenant-eide tabeller, en applikasjonsrolle uten `BYPASSRLS`, og `app.tenant_ids` satt med `SET LOCAL` i en transaksjon per forespørsel og jobb via `ApplyTenantRls`.

Lokalt og i CI kobler appen til som superbruker, og superbrukere hopper alltid over RLS. Uten et grep ville RLS aldri bli testet før produksjon.

## Beslutning

1. **`App\Tenancy\Rls\TenantRls::run($tenantIds, $callback)`** åpner en transaksjon og kjører
   `SET LOCAL ROLE ekko_app` og `set_config('app.tenant_ids', '1,2', true)` før callbacken.
   - I produksjon logger appen inn som `ekko_app`, så rollebyttet gjør ingen forskjell der.
   - Lokalt og i tester bytter superbrukeren til `ekko_app`, så policyene faktisk gjelder.
   - Byttet er også et ekstra vern: logger appen ved en feil inn med en sterkere rolle, gjelder RLS fortsatt i forespørsler og jobber.
2. **Rollenavnet** settes i `config/database.php` (`database.rls.role`, `DB_RLS_ROLE`, standard `ekko_app`). Migreringen `create_app_role_and_grants` lager rollen som `NOLOGIN` hvis den mangler, gir den DML-rettigheter på alle tabeller (også fremtidige via `ALTER DEFAULT PRIVILEGES`) og stopper hvis rollen er superbruker eller har `BYPASSRLS`. I produksjon lages rollen som Cloud SQL-bruker av Terraform (T09) før migreringen kjører.
3. **Uten tenant er ingenting synlig.** Er `app.tenant_ids` tom eller ikke satt, gir policyen ingen rader.
4. **Policyen** heter `tenant_isolation` og er `FOR ALL` med samme betingelse i `USING` og `WITH CHECK`, så den stopper både lesing og skriving til andre tenants. Tabellene får `FORCE ROW LEVEL SECURITY`, så den gjelder også tabelleieren.
5. **Migreringshjelper:** `TenantSchema::enableRowLevelSecurity($table)` og `disableRowLevelSecurity($table)`.
6. **Revisjon:** `RlsAudit::tablesMissingRls()` finner tabeller med `tenant_id` uten fullstendig RLS, inkludert svekkede policyer. `tests/Feature/Tenancy/RlsCoverageTest` feiler for slike tabeller, så en ny tenant-eid tabell uten RLS (T08 og senere) stopper i CI.
7. **Web og API:** `ApplyTenantRls` ligger i web- og API-gruppen, etter autentisering og `SetCurrentTenant` og før route-binding (`SubstituteBindings`), så bundne modeller lastes under RLS. Transaksjonen rulles tilbake ved serverfeil (5xx). Tenantene er nåværende tenant. T05 utvider med partnerens kunde-tenants.
8. **Jobber:** `TenantJob` har `SetTenantContext` og `ApplyTenantRls` som middleware. Jobben ser bare sin egen tenant.
9. **Nestede kall:** `SET LOCAL` overlever en frigitt savepoint. Når `run()` kjøres inne i en annen transaksjon (en jobb som sendes synkront fra en forespørsel, eller tester i en `RefreshDatabase`-transaksjon), settes forrige rolle og tenants tilbake etterpå.
10. **Admin:** Tilkoblingen `pgsql_admin` (`DB_ADMIN_USERNAME`, `DB_ADMIN_PASSWORD`) er for plattformadmin-handlinger med en rolle som har `BYPASSRLS`. Bruken og loggingen kommer i T05.

## Konsekvenser

- Hver forespørsel er én databasetransaksjon. Jobber som venter lenge på eksterne kall (motoradaptere, opptil 120 sekunder), holder en åpen transaksjon så lenge. Se Board-funn for T17.
- Kode utenfor forespørsler og jobber (Artisan-kommandoer, Scheduler-endepunkter) ser ingen tenant-rader i produksjon, med mindre den bruker `TenantRls::run()` eller admin-tilkoblingen.
- MCP-rutene (T34) må ligge i web- eller API-gruppen eller bruke `ApplyTenantRls` selv.
