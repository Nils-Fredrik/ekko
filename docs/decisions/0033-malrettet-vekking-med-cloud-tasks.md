# 0033 – Målrettet vekking med Cloud Tasks og reservekontroll

- **Status:** Foreslått
- **Dato:** 2026-11-19
- **Board:** `task:75` / T40-F4, del 3 av 3 under T40. Bygger på [ADR 0030](0030-avgrenset-worker-start.md) (avgrenset worker-start) og [ADR 0032](0032-varig-hendelsesstyrt-arbeid.md) (varige arbeidssignaler og kjeding).

## Kontekst

Etter del 1 og 2 registreres behandlingsbehov varig i `work_signals`, og ferdig arbeid signalerer neste steg bak `ekko.work.chaining`. Vekkingen går likevel gjennom databasekøen, og en worker startes bare av de faste planene: `extraction-poll` starter `ekko-wake` hvert 15. minutt også uten arbeid, og `aggregate` og `usage-rollup` starter `ekko-task` hver time og regner om alle tenants. Cloud Run-jobber faktureres per instans med minimum ett minutt (spec 17.3), så tomme starter koster, og dashboardet ligger opptil en time etter.

PostgreSQL skal fortsatt være arbeidskø og sannhetskilde. Cloud Tasks brukes bare til autentisert, tidsbestemt vekking.

## Beslutning

### Flyt

1. `WorkSignals` publiserer vekkingen etter commit som før (ADR 0032). Med `EKKO_WORK_PUBLISHER=cloud_tasks` lager `CloudTasksWorkPublisher` én HTTP-oppgave i køen `ekko-work`:
   - oppgave-ID: `<16 hex av sha256(nøkkel)>-<nøkkel>`, der nøkkelen er `WorkWakeUp::key()`. ID-en er deterministisk, så samlede signaler gir én oppgave. Hash-prefikset sprer ID-ene; Cloud Tasks blir tregere med sekvensielle ID-er eller prefikser.
   - `scheduleTime` = `notBefore`, også for neste statuskontroll av en aktiv modellbatch (`defer`) og for backoff (`retry`).
   - mål: fast `POST {app.url}/internal/work/wake`, med Google OIDC-token for tjenestekontoen `ekko-tasks` og audience `{app.url}/internal/work`.
   - innhold: bare `{"tenant_id": …, "signal_id": …}`. Aldri råsvar, hemmeligheter, kommandoer eller URL-er fra data.
2. Web verifiserer token (utsteder, signatur, utløp, e-post `ekko-tasks`, verifisert e-post og audience) i `AuthenticateWorkTasks`. Scheduler-tokenet gjelder ikke her, og wake-tokenet gjelder ikke på `/internal/tick/*`. Innholdet valideres strengt: nøyaktig to positive heltall, ellers 422.
3. `WorkWakeReceiver` leser tenant og signal under tenantens egen kontekst og RLS. En signal-ID fra en annen tenant blir ikke funnet. Web behandler aldri arbeidet selv og starter aldri workeren direkte:
   - ukjent tenant eller signal, personlig team, stengt tenant, ferdig, stoppet, eller ventende med et senere tidspunkt (backoff, utsettelse) → **204**. Det senere tidspunktet har sin egen vekking.
   - levende lease → **425**: en behandler arbeider; Cloud Tasks kommer tilbake i tilfelle den dør.
   - forfalt uten levende lease (også utløpt lease etter krasj) → `ProcessWorkSignal` legges i databasekøen, unik per signalversjon, og `WorkerWake::ensure()` sørger for en worker → **425**.
4. `WorkerWake::ensure()` foretrekker en aktiv worker: finnes det en køjobb reservert innenfor reservasjonstiden (450 s), er en workerprosess midt i en jobb og henter neste etterpå, og ingenting startes. Ellers vekkes den faste `ekko-wake` med `DispatcherLauncher` (ADR 0030, ingen overrides), høyst én gang per `ekko.work.wake.launch_cooldown_seconds` (120 s). Bare dispatch-identiteten kan starte `ekko-worker`, og den gjør det bare når ingen worker-eksekvering er aktiv.
5. Når workeren har leaset eller kvittert signalet, svarer neste levering 204, og oppgaven er ferdig. 425 brukes fordi Cloud Tasks prøver alle svar utenom 2xx på nytt, mens 429 og 503 bremser hele køen og 5xx teller i web-varselet.

**Køvekking:** En workerprosess som stopper (`WorkerStopping`) mens køjobber venter på et senere tidspunkt (en `release`, for eksempel kvotegrense eller `ProcessWorkSignal` som venter på en lease), publiserer en køvekking til `POST /internal/work/queue-wake` på det tidligste tidspunktet (oppgave-ID per sekund, så alle prosessene i eksekveringen samles). Står en jobb klar i det workeren stopper, publiseres vekkingen med en gang. Mottakeren vekker dispatcheren når jobber står klare (425 til de er tatt), og publiserer neste køvekking når bare forsinkede jobber venter. Dermed trengs ingen fast poll for å gjenoppta forsinket arbeid.

### Leveringsgarantier

- **Minst én gang, idempotent:** Cloud Tasks kan levere flere ganger og for sent. Signalraden er sannheten, `ProcessWorkSignal` er unik per signalversjon fram til en worker starter den, og leasen hindrer samtidig behandling. En dobbel eller sen levering finner ingenting å gjøre og svarer 204.
- **2xx betyr ikke ferdig behandlet.** Det betyr at leveringen ikke har mer å vokte: arbeidet er gjort, stoppet, eller har et senere tidspunkt med egen vekking. Inntil workeren har tatt arbeidet, holdes leveringen åpen med 425.
- **Krasj før publisering** (commit, så død før `afterCommit`): signalet ligger varig, og reservekontrollen publiserer det. **Krasj etter oppretting:** oppgaven leveres; et nytt forsøk på samme publisering gir `ALREADY_EXISTS`, som regnes som publisert.
- **Cloud Tasks-feil:** forbigående feil (forbindelse, 429, 5xx) prøves inntil tre ganger. Annen feil logges med unntaksklassen, og signalet blir liggende til reservekontrollen.
- **Workerkrasj:** leasen utløper etter 360 s, og neste levering (eller reservekontrollen) legger arbeidet i køen og vekker på nytt.
- **Worker som avslutter rett etter at ny oppstart er avvist:** leveringen er fortsatt åpen (425). Etter nedkjølingen vekkes dispatcheren igjen, og den starter workeren når den forrige eksekveringen er ferdig. Workeren som stoppet, har i tillegg publisert en køvekking.
- **Avsluttede batcher:** handleren kvitterer når batchjobben ikke lenger er `submitted`. Etter 25 timer gis batchen opp (ADR 0032). Det blir ingen endeløs polling.
- **Betalte kall:** vekkingen endrer ingenting i innsendingen. En innsending skjer bare under lease, i `ExtractionSubmitter` med advisory-lås per tenant og filter mot svar i en innsendt batch. Tvetydige innsendinger sendes ikke på nytt (ADR 0032, eierspørsmål #910).

### Reservekontroll

Cloud Scheduler kaller `POST /internal/tick/work-recover` på web hvert time (minutt 7) med Scheduler-identiteten. `WorkRecovery`:

- publiserer vekking på nytt for ventende, forfalte signaler uten levende lease, etter 300 s slakk: aldri publisert, publisering feilet, eller utløpt lease. Det er én indeksert lesing per tenant under RLS. Ingenting regnes om, og stoppede signaler røres ikke (de varsles som `work_stopped`).
- vekker dispatcheren hvis køjobber står klare uten at en worker arbeider, og publiserer en køvekking for forsinkede jobber.

En tom time gir derfor én web-forespørsel og ingen jobbstart. `ekko:work:recover` gjør samme sveip fra kommandolinjen.

### IAM (minste rettighet)

- `ekko-tasks`: egen tjenestekonto uten roller. Den beviser bare at oppgaven kom fra Ekkos kø. Har `run.invoker` på `ekko-web`, som trengs bare hvis offentlig tilgang slås av.
- `roles/cloudtasks.enqueuer` på køen `ekko-work` (ikke prosjektet) for `web`, `worker` og `task`, som registrerer arbeid. `dispatch` registrerer ikke arbeid og får ingenting.
- `roles/iam.serviceAccountUser` på kontoen `ekko-tasks` (ikke prosjektet) for de samme tre, som gir `actAs` og kreves for å lage oppgaver med OIDC-token for den (`work_tasks_act_as` i `infra/terraform/work.tf`).
- `roles/iam.serviceAccountUser` på kontoen `ekko-tasks` også for Cloud Tasks-tjenesteagenten (`work_tasks_agent`). Rollen gir `actAs`, ikke `iam.serviceAccounts.getOpenIdToken`; selve tokenutstedelsen ved levering dekkes normalt av agentrollen `roles/cloudtasks.serviceAgent`. Bindingen er derfor trolig overflødig, men beholdes til den er testet på trinnet `ready` (eierbeslutning, Board #945).
- Kjeden fra ADR 0030 er uendret: web kan bare starte `ekko-wake` uten overrides, bare `dispatch` kan starte `ekko-worker`, og `ekko-tasks` kan ingenting i Cloud Run utover å kalle web.

### Varig utrullingstrinn

Produksjonsvalget ligger i versjonert kode, ikke i en lokal tfvars: `local.work_rollout_stage` i `infra/terraform/work.tf` med trinnene `legacy` (produksjonen da ADR-en ble skrevet), `ready`, `shadow` og `event` (se fila og [produksjonsaksepten](../t40-f4-produksjonsaksept.md)). Trinnet styrer API, kø, identitet og IAM, miljøvariablene `EKKO_WORK_CHAINING`, `EKKO_WORK_PUBLISHER` og `EKKO_WORK_TASKS_*`, reservekontrollen og om `extraction-poll`, `aggregate` og `usage-rollup` kjører. Fra `ready` overstyrer trinnet `enable_dashboard_schedules` for disse tre, så en annen arbeidskopi med annen tfvars ikke kan endre dem. Andre planer endres ikke.

Det anvendte trinnet skrives som etiketten `ekko-work-rollout` på `ekko-wake`. Planen leser etiketten, og en forutsetning stopper en plan som ville flytte trinnet bakover, for eksempel fra en gren laget før siste trinnendring. Bevisst tilbakerulling krever `-var=allow_work_rollout_downgrade=true` for den ene gjennomgåtte planen. Vernet gjelder fra denne endringen er merget. Kode fra før den kjenner ikke vernet, og det er en grunn til å planlegge bare fra oppdatert `origin/main`.

### Brytere og standardverdier

| Bryter | Standard | Virkning |
|---|---|---|
| `work_rollout_stage` (Terraform-lokal) | trinnet i `main` | Levert som `legacy` (ingen Cloud Tasks-ressurser). Produksjonen kjører trinnet i `main` etter godkjent apply; status står i produksjonsaksepten |
| `EKKO_WORK_PUBLISHER` | `queue` | `cloud_tasks` sender vekking via Cloud Tasks |
| `EKKO_WORK_CHAINING` | `false` | Produsentene signalerer (ADR 0032) |
| `allow_work_rollout_downgrade` (Terraform-variabel) | `false` | Tillater én bevisst tilbakerulling |

Ruten svarer uavhengig av bryterne. Etter en tilbakerulling tømmes oppgaver som allerede er ute, på vanlig måte. Det er ufarlig fordi behandlingen er idempotent.

### Tilbakerulling

Trinnet settes tilbake til `ready` (eller `legacy`) i en PR og anvendes med `allow_work_rollout_downgrade=true`. Da blir `EKKO_WORK_CHAINING=false` og `EKKO_WORK_PUBLISHER=queue`, og de tre gamle planene gjenopptas. Ventende signaler blir liggende. Oppgaver som allerede er ute, og reservekontrollen (som kjører fra `ready`), tømmer dem, inkludert statuskontroller av batcher som ble sendt inn med kjedingen. Gamle planer sender ikke svar som ligger i en innsendt batch, så ingen batch betales to ganger. `WorkChainTest` («rollback: …») tester dette. Ved akutt behov kan køen settes på pause (`gcloud tasks queues pause ekko-work`) og de gamle planene gjenopptas med Scheduler-CLI før Terraform-rettingen. Køen slettes ikke: navnet kan ikke brukes igjen på en stund.

### Kostnadsmåling

Målemetoden står i [produksjonsaksepten](../t40-f4-produksjonsaksept.md): starter av `ekko-wake`, `ekko-worker` og `ekko-task` per periode, fakturert vCPU- og GiB-sekunder per jobb og for web, databasearbeid (Query Insights eller `pg_stat_statements`), Cloud Tasks-forespørsler og leveringsforsøk, og leverandørens statuskall (loggmeldingen `extraction.status_check`, som skrives for hvert statuskall både fra kjedingen og fra `PollExtractionBatch`). Baseline (`legacy`) og `shadow`/`event` måles ved tomgang og ved representativ last. Estimater er merket som estimater. Ingen besparelse påstås uten målte tall.

## Konsekvenser

- Tomme perioder gir ingen behandlingsstarter, bare én web-forespørsel i timen fra reservekontrollen.
- Første vekking etter tomgang koster to jobbstarter (`ekko-wake` og `ekko-worker`), som i dag per `extraction-poll`. Hver vekking gir typisk 2–5 Cloud Tasks-leveringer (425 til arbeidet er tatt). Prisen per leveranse er lav, men leveringene vises i loggen.
- Web får nye interne ruter, og de er registrert i isolasjonsregisteret. `WorkWakeEndpointTest` dekker identitet, audience, tverr-tenant-forsøk, misdannede parametere, doble og sene leveringer, kappløpet ved avslutning av worker, krasj før og etter publisering, utløpt lease, utsettelse og køvekking.
- Ventende forsinkede køjobber gjenopptas av køvekkingen i stedet for den faste pollen. Den daglige `dispatch` og andre planer er uendret.
