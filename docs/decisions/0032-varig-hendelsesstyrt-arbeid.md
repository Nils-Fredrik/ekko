# 0032 – Varig registrering av hendelsesstyrt behandlingsarbeid

- **Status:** Foreslått
- **Dato:** 2026-11-19
- **Board:** `task:73` / T40-F2 (del 1 av 3 under T40; del 2 er `task:74` / T40-F3, del 3 er `task:75` / T40-F4)

## Kontekst

I dag starter faste planer behandlingen: `extraction-poll` hvert 15. minutt, `aggregate` og `usage-rollup` hver time (spec 7.1, ADR 0030). Det gir både forsinkelse og arbeid uten noe å gjøre. Eierens plan er at ferdig applikasjonsarbeid skal utløse neste steg, med PostgreSQL som arbeidskø og sannhetskilde og Cloud Tasks bare for målrettet vekking.

En unik køjobb alene er ikke nok. Den mister signaler som kommer mens jobben kjører, den overlever ikke at prosessen dør mellom commit og kølegging, og den kan ikke bære en vekking utenfor databasen (Cloud Tasks) atomisk. Del 1 lager derfor en varig registrering som del 2 kobler produsentene til og del 3 vekker med Cloud Tasks. Dagens produksjonsløp endres ikke.

## Beslutning

### Datamodell

Tabellen `work_signals` er tenant-eid (`BelongsToTenant`, `FORCE ROW LEVEL SECURITY`, ordinær `ekko_app`), med én rad per `(tenant_id, kind, scope)`:

| Kolonne | Betydning |
|---|---|
| `kind` | `extraction`, `aggregation` eller `usage_rollup` (`App\Work\WorkKind`) |
| `scope` | Bare interne identifikatorer: `tenant` eller `run_batch:<id>` for uttrekk, lokal dato `Y-m-d` for aggregering, UTC-måned `Y-m` for forbruk. En `CHECK` i databasen avviser fritekst. |
| `version` / `processed_version` | Raden venter når `version > processed_version` |
| `available_at` | Tidligste behandlingstid (forsinkelse, retry eller utsettelse) |
| `lease_token`, `lease_version`, `leased_at`, `lease_expires_at` | Leasen til en aktiv behandler |
| `attempts`, `last_error` | Mislykkede forsøk siden siste kvittering; bare unntaksklassen lagres |
| `deferred` | Handleren har utsatt raden (`retryAt`); nye signaler beholder tidspunktet |
| `failing_since`, `stopped_at` | Start på sammenhengende feil, og når signalet ble stoppet etter for lang feilperiode |
| `pending_since`, `last_signaled_at`, `processed_at` | Ventetid og måling (del 2 og 3) |

Det finnes ikke noe metadata-felt. Signalet sier bare «noe i dette omfanget er endret»; behandleren leser gjeldende tilstand selv. Dermed havner verken råsvar, hemmeligheter eller kundedata i tabellen, vekkingen eller loggene.

### Atomisk registrering og publisering etter commit

`WorkSignals::signal(kind, scope, notBefore)` gjør en upsert som øker `version` i kallerens transaksjon, under kallerens RLS. Den avviser kall uten tenant i konteksten og kall utenfor en transaksjon. Data og behov blir dermed committet sammen eller rullet tilbake sammen. Vekkingen (`WorkWakeUp`) registreres med `afterCommit` og publiseres først etter commit. Ved rollback, også av et savepoint, forsvinner både raden og vekkingen.

### Samling med versjonsteller

- Gjentatte signaler for samme omfang gir samme rad og høyere `version`. Mens raden venter, beholdes det tidligste tidspunktet. En rad i backoff eller med en utsettelse fra handleren (`deferred`) beholder tidspunktet sitt, uavhengig av tidligere feil. Den utsatte eller nye kjøringen tar med seg de nye versjonene.
- En behandler leaser gjeldende versjon (`claim`) og kvitterer bare for den: `processed_version = lease_version`. Signaler som kommer under kjøringen, gir høyere `version`, og raden blir stående. Kvitteringen publiserer da en ny vekking (`remaining`) etter commit.
- Et signal mot en leaset rad publiserer ingenting, fordi kvitteringen vekker. Et signal mot en rad i backoff eller en utsatt rad publiserer heller ingenting, fordi feilen eller utsettelsen allerede har planlagt vekking. Dermed gir nye signaler ikke ekstra statuskall mot en modellbatch som fortsatt kjører.

### Lease, retry og krasj

`WorkProcessor` behandler et signal i tre korte RLS-transaksjoner:

1. **Leasing:** leasen committes for seg, så andre behandlere ser den. Kravet er at raden venter, er forfalt og ikke har en levende lease. Leasingen venter på radlåsen til en produsent som ikke har committet, i stedet for å hoppe over raden. `attempts` økes.
2. **Behandling:** handlerens skrivinger og kvitteringen eller utsettelsen committes sammen. Ved krasj eller unntak rulles begge tilbake. Hvis leasen er overtatt, kvitterer tokenet ingenting (`LeaseLost`), og handlerens skrivinger rulles tilbake.
3. **Feil:** leasen gis tilbake i egen transaksjon med `available_at = nå + min(maks, grunn · 2^(forsøk−1)) + jitter`, og en `retry`-vekking publiseres. Forsøk logges som advarsel, og fra `alert_after_attempts` som feil. Det er ingen grense på antall forsøk, men en tidsgrense (eierens beslutning, task:73): `failing_since` settes ved første feil i en sammenhengende feilperiode og nullstilles av kvittering eller utsettelse. Når perioden er lengre enn `ekko.work.stop_after_seconds` (standard 24 timer), settes `stopped_at` i stedet for ny backoff. Et stoppet signal leases ikke, vekkes ikke og gjenopprettes ikke av sveipet, men raden slettes ikke og blir stående som ventende.

En handler kan returnere `WorkOutcome::retryAt(t)`, for eksempel når en ekstern modellbatch fortsatt kjører. Da utsettes signalet uten at det teller som feil, og en `defer`-vekking planlegges til `t`.

Leasen varer `ekko.work.lease_seconds` (360 s), lenger enn jobbens `timeout` (300 s). Når leasen utløper, kan en annen behandler overta signalet. Overtakelsen logges, og et forsøk som aldri ble ferdig (krasj eller timeout) teller mot `alert_after_attempts` som en feil, så en handler som alltid bruker for lang tid, gir feillogg. Jobben `ProcessWorkSignal` arver `TenantJob` og bruker `SetTenantContext`, men ikke den ytre `ApplyTenantRls`-transaksjonen. Ellers ville leasen ikke vært synlig før jobben var ferdig. Som `CollectEngineRun` (ADR 0020) og noen andre jobber med egne transaksjonsfaser kjører hvert databaseavsnitt med `TenantRls::run` for jobbens tenant. `WorkProcessor::process()` avviser kall inne i en åpen transaksjon (under testkjøring tillates ett nivå for den transaksjonelle testen).

En stengt tenant behandles ikke (T39-F6). En type uten konfigurert handler (`ekko.work.handlers`) behandles som feil, så signalet blir stående og synlig i loggen i stedet for å bli kvittert ubehandlet.

### Stopp og varsel

- Overgangen til stoppet logges én gang som `work.stopped` med bare tenant-ID, signal-ID, type, omfangstype (`tenant`, `run_batch`, `date`, `month`), antall forsøk og unntaksklasse.
- Varselet går gjennom den eksisterende operasjonelle overvåkingen (ADR 0029): `ekko:monitor-health` teller stoppede signaler per tenant under RLS og logger `monitoring.issue` med `signal=work_stopped` og antall. Terraform-policyen for applikasjonssignaler får `work_stopped` i settet sitt. Varselet står til signalet er startet igjen, med samme takt og automatisk lukking som de andre signalene. Terraform-endringen tas i bruk ved neste gjennomgåtte apply; ingen produksjonsendring er gjort i denne PR-en.
- En lease som utløper og overtas, regnes som et feilet forsøk. Varer det i hele stoppvinduet (en handler som alltid bruker for lang tid), stoppes signalet med `last_error = LeaseExpired`.
- Et nytt `signal()` for samme tenant, type og omfang starter et stoppet signal igjen. Nye data kan lykkes der de gamle feilet, så feilperioden og forsøkene nullstilles og vekking publiseres. Dette betyr at et signal som feiler på grunn av en varig feil, kan stoppes og startes igjen flere ganger når nye data fortsetter å komme; hver gang går det likevel et nytt døgn før det stoppes og varsles igjen.
- `php artisan ekko:work:retry <tenant-id> <signal-id>` starter et stoppet signal igjen manuelt etter at årsaken er rettet. Kommandoen kjører i den oppgitte tenantens kontekst og RLS, så en signal-ID fra en annen tenant blir ikke funnet.

### Gjenoppretting

`ekko:work:recover` går gjennom tenantene som ikke er stengt, under hver tenants RLS, og publiserer vekking på nytt for ventende signaler som er forfalt uten levende lease. Det gjelder også signaler der leasen har utløpt. Ikke-leasede signaler får `recover_after_seconds` (300 s) slakk, så sveipet ikke konkurrerer med vanlig publisering. Sveipet leser bare ventende rader (delvis indeks) og omberegner ingenting. Det dekker krasj mellom commit og publisering, feilet publisering og krasj under behandling. Kommandoen planlegges ikke i del 1; del 3 kjører den som den sjeldne reservekontrollen.

### Vekkekontrakt for del 3

`WorkPublisher::publish(WorkWakeUp)` kalles bare etter commit. Den må være idempotent for samme `key()` og kan kaste unntak. `WorkSignals::publish` fanger unntaket og logger bare klassen, og signalet ligger varig til neste vekking eller sveip. `WorkWakeUp` har:

- `payload()`: bare `{tenant_id, signal_id}`, aldri råsvar, hemmeligheter eller kommandoer
- `notBefore`: tidspunktet oppgaven skal kjøres (Cloud Tasks `scheduleTime`)
- `key()`: `ws-<tenant>-<signal>-<processed_version>-<available_at i ms>-<reason>` (`[a-z0-9-]`), brukbar som deterministisk Cloud Tasks-oppgave-ID. Signaler i samme ventende periode med samme tidspunkt og årsak får samme nøkkel og samles. Retry og utsettelse har egen årsak og eget tidspunkt og får ny nøkkel, også når en utsettelse havner i samme millisekund som vekkingen som kjørte den. Gjenopprettingsvekking får sveipetiden som suffiks, fordi den opprinnelige ID-en kan være brukt opp av en levering som ikke fant signalet ledig.
- `reason`: `signal`, `remaining`, `retry`, `defer` eller `recovery`, for måling

Levering kan skje flere ganger og for sent. Mottakeren (`ProcessWorkSignal` eller del 3s HTTP-mål) kaller `WorkProcessor::process(signal_id)` i tenantens kontekst. Et resultat med `BUSY` og `busyUntil` betyr «kom tilbake da»: køjobben frigir seg selv, og del 3 kan svare med en feilkode så Cloud Tasks prøver igjen, eller planlegge ny vekking. HTTP 2xx betyr bare at vekkingen er mottatt. Raden er kvitteringen.

Del 1 har to publisere (`ekko.work.publisher`): `queue` (standard), som legger `ProcessWorkSignal` på standardkøen, unik per nøkkel til den starter, og `none`, der bare sveipet vekker. Del 3 legger til Cloud Tasks bak samme grensesnitt.

### Grensesnitt mot del 2

Del 2 kaller `signal()` i samme transaksjon som lagringen. Det gjelder etter innhenting (`extraction`, `run_batch:<id>` eller `tenant`), etter lagret uttrekk (`aggregation` per lokal dato fra `responses.collected_at` i tenantens tidssone, `WorkScope::localDate`) og etter registrert `UsageEvent` (`usage_rollup` per UTC-måned, `WorkScope::month`). Del 2 registrerer `WorkHandler`-klasser i `ekko.work.handlers` som gjenbruker `SubmitExtractionBatch`, `DailyAggregator` og `UsageRollup`. Handlerne må være idempotente og lese gjeldende tilstand. Statuskontroll av en aktiv modellbatch blir en `retryAt`-utsettelse. Del 2 eier også funksjonsbryteren for produsentene.

### Kostnadsstrategi

- Ingen arbeid, ingen start: tomme perioder gir verken rader, vekkinger eller behandlingsjobber. Sveipet er én indeksert lesing per tenant.
- Samling: én rad og én ventende vekking per omfang, uansett antall signaler. Et signal mot en leaset rad eller en rad i backoff publiserer ingenting.
- Arbeid som ikke kan fullføres, ventes ut med eksponentiell backoff opp til én time og stoppes etter 24 timer. Det blir ingen tett løkke, ingen evig prøving og ingen ny betalt innsending bare for å sjekke status.
- Del 3 måler Cloud Tasks-forespørsler og Cloud Run-starter mot dagens planer før gamle planer slås av.

## Leveringsgarantier

- **Minst én gang:** et committet signal blir behandlet, også etter krasj før publisering, feilet publisering, krasj under behandling eller tapt lease, så lenge sveipet kjører. Unntaket er et signal som har feilet i hele stoppvinduet: det stoppes med varsel og venter på nytt signal eller `ekko:work:retry`, men slettes ikke.
- **Ingen spøkelsesarbeid:** rollback gir verken signal eller vekking.
- **Ingen tapte oppdateringer:** en kvittering dekker bare versjoner som var registrert da leasen ble tatt. Senere signaler blir stående.
- **Ingen feil kvittering:** et utløpt og overtatt token kvitterer ingenting, og de databaseendringene det ville gjort, rulles tilbake.
- **Ikke nøyaktig én gang for eksterne kall:** en handler som kaller en leverandør og så krasjer før commit, kan kjøre kallet på nytt. Slike handlere må være idempotente eller avstemme mot leverandøren, slik ADR 0011 og 0020 allerede krever.

## Konsekvenser

- Ingen produsenter er koblet til i del 1, og ingen planer er endret. Produksjonsløpet er det samme som før.
- `ProcessWorkSignal` er et nytt, avgrenset unntak fra den ytre RLS-transaksjonen. Det må ikke kopieres til vanlige jobber.
- Handlerne må bli ferdige innen leasen. Lengre arbeid deles opp eller bruker `retryAt`.
- Tester: `tests/Feature/Work/WorkSignalsTest.php`, `tests/Feature/TenantIsolation/WorkSignalIsolationTest.php` og `tests/Settlement/WorkSignalCommitTest.php`. Den siste bruker ekte commit og en ekstra databaseforbindelse.
