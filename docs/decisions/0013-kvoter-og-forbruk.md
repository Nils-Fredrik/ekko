# 0013 – Kallkvote, budsjetter og forbruksoppdatering

- **Status:** Foreslått
- **Dato:** 2026-11-15
- **Board:** `task:15` / T19

## Kontekst

En tenant kan kombinere motorkall med plattformens og egne nøkler. Begge krever kvotekontroll, og plattformbudsjettet skal bare belastes for kall betalt av plattformen. Timeoppdateringen behandler flere tenants under RLS. A7 har uavklarte startbudsjetter, priser og påslag.

## Beslutning

- Kallkvoten gjelder alle kall i UTC-måneden. Plattformadmin kan overstyre pakkens kallkvote per tenant. Budsjettet per betalingsmåte gjelder bare kall som belastes det budsjettet. `null` betyr at grensen ikke er satt. En grense på null stopper nye kall. Ved null forbruk oppstår ingen terskel selv om grensen er null.
- `UsageMonthly` lagrer kall, plattformkostnad, kostnad med egne nøkler og gjeldende grenser. Timejobben tar vare på grensene for foregående måned og oppdaterer faktiske summer for den måneden hvis den allerede er aggregert. Den lagrer ett varsel per terskel per tenant og måned. T24 leverer varslene til tenant-admin og plattformadmin.
- T17 bruker `MonthlyUsage::forAdmission` med tenant-kontekst og `QuotaGuard::allowedRunIds` før kølegging. Planlagte kjøringer har promptprioritet, betalingsmåte og estimert kostnad. Dispatcheren markerer de øvrige kjøringene med `skipped_quota`. Den synkrone innsamleren sjekker forbruket og fast kallpris på nytt før leverandørkallet. Den returnerer `null` ved kvotestopp etter å ha lagret statusen. Innsamleren reserverer ett kall og fast kallpris i en kort transaksjon med tenantlås. Deretter kan leverandørkall for samme tenant kjøre parallelt. Innsamleren gjør opp reservasjonen i samme transaksjon som svar og forbrukshendelse. Kostnader som avhenger av svarlengde og antall søk kommer først inn i regnskapet når leverandøren svarer.
- En PostgreSQL-sesjonslås for kjøringen hindrer dobbel innsamling. Reservasjonen holder av kapasitet mens sesjonslåsen finnes og i minst 450 sekunder ved tapt databaseforbindelse. Denne fristen dekker workerens tidsgrense på 420 sekunder. Et kontrollert avbrudd frigjør reservasjonen. Etter et krasj kan en ny worker overta når fristen er ute og sesjonslåsen er frigitt.
- `CollectEngineRun` arver `TenantJob` og kjører innsamleren med `SetTenantContext`. Innsamleren setter RLS selv rundt hver databasefase. Jobben gjør all datatilgang gjennom innsamleren. Andre tenant-jobber beholder standard `ApplyTenantRls` rundt `handle`.
- Cloud Scheduler starter `ekko-task` med `ekko:usage-rollup` hver time. Jobben behandler aktive og pausede tenants under ordinær RLS, med én transaksjon per tenant. Scheduler bruker eksisterende IAM for oppgavejobben.
- En API-nøkkel verifiseres med en modelliste fra leverandøren. Bare verifiserte nøkler lagres. Nøkkelen krypteres, holdes ute av modellens serialiserte felter og maskeres i revisjonsloggen. BYOK-budsjettendringer logges i tenantens revisjonslogg. Pakker tilhører plattformen. Pakkeendringer logges strukturert med pakke-ID, aktør og feltnavn etter commit.
- Seederen oppretter de avtalte pakkene og bevarer senere endringer i pakkene. Startbudsjetter, priser og påslag står åpne i A7.

## Konsekvenser

- T17 kobler kvotekontrollen til dispatcheren for både planlagte og manuelle kontroller. Kostnadsestimatet for hver kjøring må komme fra dispatcheren. T17 kølegger `CollectEngineRun` etter at kjøringen er lagret.
- T24 bruker de lagrede kvotevarslene. T28 kobler innstillingene til `TenantApiKeys::store` og `TenantApiKeys::setBudget`.
- Råsvar kan bli skrevet før oppgjøret feiler. Aldersregelen i rådatabøtten sletter alle råobjekter etter 400 dager, også objekter uten en svarrad.
- Scheduler bruker oppgavejobben timevis med samme oppstartskostnad som de øvrige Cloud Run-jobbene.
