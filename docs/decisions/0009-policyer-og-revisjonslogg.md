# 0009 – Policyer for tenant-eide modeller og revisjonslogg

- **Status:** Akseptert
- **Dato:** 2026-11-15
- **Board:** `task:44` / oppfølging av T05

## Kontekst

T05 la grunnlaget med `TenantAccess`, `TenantPermission` og basisklassen `TenantResourcePolicy`. T08 la inn modellene og tabellen `activity_log`. Rolletabellen i spesifikasjonen (5.2) sier ikke hvilken rettighet hver tabell hører til, og 16 lister hva revisjonsloggen skal dekke, men ikke hvordan.

## Beslutning

**Policyer.** Hver modell med `BelongsToTenant` har en policy. Tester feiler hvis en ny tenant-eid modell mangler policy eller oppføring i rettighetstabellen i `TenantModelPolicyTest`.

| Data | Lese | Endre |
|---|---|---|
| Merkevarer (med alias og domener), markeder og motoroppsett | alle roller | `brands.manage` / `markets.manage` (admin) |
| Prompts, temaer, konkurrenter (med alias og domener) | alle roller | `prompts.manage` / `competitors.manage` (admin, redaktør) |
| Varselregler og varsler, rapportplaner og rapporter | alle roller | `alerts.manage` / `reports.manage` (admin) |
| Integrasjoner | `tenant.update` (admin) | `tenant.update` (admin) |
| Kjøringer, svar, omtaler, sitater, aggregater, robot- og trafikktall | alle roller | ingen; skrives av systemet |
| Kjørebatcher | alle roller | opprette: `checks.run` (manuell kontroll); ellers ingen |
| Forbruk | `usage.view` (admin) | ingen |
| Revisjonslogg | `members.manage` (admin) | ingen |
| Uttrekksjobber | ingen | ingen |

Integrasjoner har legitimasjon i konfigurasjonen, så også lesing krever admin. Uttrekksjobber er intern bokføring.

**Revisjonslogg.** `AuditLog` skriver til `activity_log` (loggnavn `audit`) og til applikasjonsloggen:
- opptre som tenant (start og stopp, med årsak)
- medlem lagt til, rolle endret (fra og til), medlem fjernet

Prompts, konkurrenter, varselregler og integrasjoner logger opprettelse, endring og sletting gjennom `RecordsRevisions`. Bare navngitte felt logges. Prompt-tekst, varselkanaler og integrasjonskonfigurasjon logges som `[changed]`, aldri med verdien (16: ingen hemmeligheter, prompts ikke i logger).

Oppføringen skrives i tenanten den gjelder, med tenant-kontekst og RLS satt til den tenanten bare for skrivingen. En plattformadmin som starter «opptre som» fra adminsiden, eller en partner-rådgiver som endrer roller i en kunde-tenant, får dermed oppføringen i riktig tenant. En godtatt invitasjon logges med det nye medlemmet som utøver, ikke eieren Jetstream legger til på vegne av.

## Konsekvenser

- `activity_log.tenant_id` er påkrevd. Endringer som ikke tilhører en tenant (pakker), og endringer i selve tenanten (`teams`) og tenant-nøkler (tabellen finnes ikke ennå), er ikke dekket her. De må få en egen løsning, for eksempel i T19 og T30.
- Nye modeller som skal i revisjonsloggen, bruker `RecordsRevisions` og lister feltene sine.
