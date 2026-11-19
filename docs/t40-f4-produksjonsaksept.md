# T40-F4 – Produksjonsaksept for målrettet vekking

Board `task:75`. Flyt og garantier: [ADR 0033](decisions/0033-malrettet-vekking-med-cloud-tasks.md). Drift: [runbook](runbook.md#målrettet-vekking-og-reservekontroll-t40-f4).

Dette er planen for fase B. **Ingenting her er gjennomført.** Hvert trinn krever eierens godkjenning av den konkrete Terraform-planen. Agenter kjører ikke `terraform apply`, `gcloud`-kommandoer som skriver, trafikkflytting eller bryterendringer i produksjon uten dette. Tall merket *estimat* er ikke målt.

## 0. Utgangspunkt og forutsetninger

- PR-en for T40-F4 er merget, og CI-utrullingen av den er grønn, slik at web og jobber har koden. Kontroll: `curl -s -o /dev/null -w '%{http_code}' -X POST https://ekko.maksimer.com/internal/work/wake` gir `401`.
- Trinnet i `infra/terraform/work.tf` er `legacy`. Produksjonen er som før: `extraction-poll`, `aggregate` og `usage-rollup` aktive (task:72), øvrige innsamlingsplaner på pause.
- Eierbeslutninger før `shadow`:
  1. Spørsmål #910 (tvetydig batchinnsending: terminal fallback eller avstemming) gjelder bare når kjedingen er på.
  2. IAM-omfanget i ADR 0033: enqueuer og actAs for `web`, `worker` og `task`, og Cloud Tasks-agenten som serviceAccountUser på `ekko-tasks`.
  3. Tidspunkt for overgangene og for avvikling av de gamle planene.
- Planlegg alltid fra oppdatert `origin/main`, med gjeldende produksjons-tfvars som `-var-file` (ignorert fil; ingen hemmeligheter). Hent den først med `scripts/terraform-vars.sh pull` (runbook):

  ```sh
  cd infra/terraform
  terraform init -reconfigure -input=false -backend-config=bucket=ekko-514612-tfstate
  terraform plan -input=false -var-file=<gjeldende production.auto.tfvars> -out=/tmp/ekko-t40f4-<trinn>.tfplan
  terraform show -no-color /tmp/ekko-t40f4-<trinn>.tfplan > /tmp/ekko-t40f4-<trinn>.txt
  ```

  Eieren godkjenner `terraform show`-utskriften. Bare akkurat den planfila anvendes: `terraform apply /tmp/ekko-t40f4-<trinn>.tfplan`. Etterpå skal en ny plan ikke vise endringer fra trinnet.

**Lesende plan i PR-en (19.11.2026):** Med trinnet `legacy` og produksjonens tfvars ga en plan med `-lock=false` (ingen tilstandsendring) `2 to add, 5 to change, 0 to destroy`. Fra denne PR-en kommer bare etiketten `ekko-work-rollout=legacy` på `ekko-wake` og vernet `terraform_data.work_rollout_guard`. Resten var der fra før: navnet på web-revisjonen nullstilles (kjent, se Terraform-README), tre varselpolicyer oppdateres og `work_stopped` opprettes (fra T36 og T40-F2, ikke anvendt). En lokal prøveplan med trinnet `event` ga `14 to add, 12 to change, 0 to destroy`: API, kø, identitet, IAM, `work-recover`, miljøvariabler på web og jobber, og `paused=true` på de tre gamle planene. Ingen andre planer ble berørt. Begge planene er for gjennomgang, ikke for anvendelse.

## 1. Baseline (trinn `legacy`)

Mål to perioder med dagens oppsett og noter vinduene i UTC:

- **Tom periode:** 24 timer uten innsamling eller veiviser (vanlig når `enable_schedules=false`).
- **Representativ arbeidsperiode:** én kontrollert innsamling med eierens godkjenning, for eksempel én dags kjøring for testtenanten med lav kvote, til alle uttrekk er skrevet. Noter antall kjøringer, svar og batcher. De andre periodene sammenlignes ved samme volum (± 10 %).

| Mål | Kilde og metode |
|---|---|
| Starter av `ekko-wake`, `ekko-worker`, `ekko-task` | `gcloud run jobs executions list --job=<jobb> --region=europe-west1 --format='value(name,createTime,completionTime)'`, filtrert på vinduet, eller metrikken `run.googleapis.com/job/completed_execution_count` per `job_name`. For `ekko-task` deles startene på kommando (aggregate, usage-rollup, alerts, monitor-health) ved hjelp av eksekveringens overstyrte argumenter. |
| Fakturert instans-tid (CPU og minne) | Metrikken `run.googleapis.com/container/billable_instance_time` summert per jobb og for `ekko-web`. Alle jobber har 1 vCPU / 1 GiB, så vCPU-sekunder = GiB-sekunder = summen. Kontroller mot faktureringsrapporten (SKU-ene for Cloud Run CPU og minne) for samme dager. |
| Databasearbeid | Cloud SQL-metrikkene `database/postgresql/transaction_count` og `database/cpu/utilization`, og Query Insights (topp-spørringer per vindu). |
| Cloud Tasks | Metrikkene `cloudtasks.googleapis.com/api/request_count` (opprettinger) og `cloudtasks.googleapis.com/queue/task_attempt_count` per `response_code`. I `legacy` er tallet 0. |
| Leverandørens statuskall | Loggmeldingen `extraction.status_check` i `ekko-worker`: `gcloud logging read 'resource.type="cloud_run_job" jsonPayload.message="extraction.status_check"' --freshness=… --format='value(timestamp)'`, telt per vindu. Gjelder både `PollExtractionBatch` og kjedingen. |
| Scheduler-kall | Cloud Scheduler-logger per jobb (`ekko-extraction-poll`, `ekko-aggregate`, `ekko-usage-rollup`, senere `ekko-work-recover`). |
| Dashboardforsinkelse | Se avsnitt 5. |

Legg tallene i en tabell under «Bevis» nederst. Lagre ingen kundedata, tenantnavn eller råsvar, bare antall, varigheter og ID-er.

## 2. Trinn `ready`: ressurser uten atferdsendring

1. PR: sett `work_rollout_stage = "ready"` i `infra/terraform/work.tf`. Board-referanse og plan i PR-beskrivelsen.
2. Plan etter avsnitt 0. Forventet: API `cloudtasks.googleapis.com`, `ekko-tasks`, køen `ekko-work`, 3 enqueuer-grants, 3 actAs-grants, agent-grant, invoker på web, Scheduler `ekko-work-recover`, og på web og alle jobber `EKKO_WORK_CHAINING=false`, `EKKO_WORK_PUBLISHER=queue` og `EKKO_WORK_TASKS_*`. Etiketten blir `ready`. De tre gamle planene skal stå `paused=false` uten endring. Ingen sletting, ingen annen IAM og ingen endring i andre planer.
3. Etter godkjenning: apply. Terraform lager en ny web-revisjon uten trafikk, fordi trafikken er låst til en navngitt revisjon. Kjør røyktesten mot kandidaten og flytt trafikken etter runbookens mønster (T39-F1, avsnitt 2). Jobbene bruker de nye variablene ved neste eksekvering.
4. Kontroll:
   - `gcloud tasks queues describe ekko-work --location=europe-west1` viser `RUNNING` og retry-konfigurasjonen.
   - IAM: `gcloud tasks queues get-iam-policy ekko-work --location=europe-west1` og `gcloud iam service-accounts get-iam-policy ekko-tasks@ekko-514612.iam.gserviceaccount.com`. Bare de forventede medlemmene skal finnes.
   - Etter neste hele time + 7 min: `ekko-work-recover` gir 204 i Scheduler-loggen og `work.recovery` med `published=0` i web-loggen. Ingen ny jobbstart skal komme fra den.
   - **Syntetisk levering (rydder seg selv):** `gcloud tasks create-http-task t40f4-synthetic-$(date +%s) --queue=ekko-work --location=europe-west1 --url=https://ekko.maksimer.com/internal/work/wake --method=POST --header=Content-Type:application/json --body-content='{"tenant_id":999999999,"signal_id":1}' --oidc-service-account-email=ekko-tasks@ekko-514612.iam.gserviceaccount.com --oidc-token-audience=https://ekko.maksimer.com/internal/work`. Forventet: én levering med 204 og `work.wake` med `outcome=unknown`. Oppgaven forsvinner etter 2xx. Kontroller også at et kall uten token gir 401.

## 3. Trinn `shadow`: vekking med reserveplaner

Forutsetning: #910 er avklart, og baseline er registrert.

1. PR: `work_rollout_stage = "shadow"`. Planen skal bare endre `EKKO_WORK_CHAINING=true` og `EKKO_WORK_PUBLISHER=cloud_tasks` på web og jobber, i tillegg til etiketten. Planene står fortsatt aktive.
2. Apply etter godkjenning, røyktest og trafikkflytting som i trinn 2.
3. Kontroll i første time: `work.wake` med `queued`, deretter `idle` eller `not_due`. Ingen 401 eller 503 fra `/internal/work/*`. Køen tømmes (`gcloud tasks list`). `work_signals` får ingen ventende rader eldre enn 1 time, og ingen stoppede.
4. Mål den tomme perioden og den representative perioden som i avsnitt 1, ved samme volum. Gamle planer kjører fortsatt, så tallene viser summen av gammel og ny vei. Tallene for den nye veien alene kommer fra trinn `event`.
5. Ingen doble betalte kall mens begge veiene kjører: hvert svar skal finnes i høyst én batchjobb.

   ```sql
   SELECT tenant_id, r.id, count(*) FROM extraction_jobs j, jsonb_array_elements_text(j.response_ids) AS r(id)
   WHERE j.created_at >= :fra GROUP BY 1, 2 HAVING count(*) > 1;  -- forventet: 0 rader
   ```

   Spørringen kjøres lesende via Cloud SQL Auth Proxy med en operatørrolle, eller i `ekko-task` under hver tenants RLS. Bare antallet noteres.

## 4. Trinn `event`: avvikling av de gamle planene

1. PR: `work_rollout_stage = "event"`. Planen skal bare sette `paused=true` på `ekko-extraction-poll`, `ekko-aggregate` og `ekko-usage-rollup`, i tillegg til etiketten. Ingen andre planer endres: `dispatch`, `alerts`, `log-sync`, rapporter, `tenant-purge` og `monitor-health` er uendret.
2. Apply etter godkjenning. Kontroller faktisk Scheduler-status med `gcloud scheduler jobs list --location=europe-west1`.
3. Mål tom og representativ periode. Forventet ved tomgang: ingen starter av `ekko-wake` eller `ekko-worker`, og ingen `ekko-task` for aggregate eller usage-rollup. `alerts` (hver time) og `monitor-health` (hvert 15. minutt) starter fortsatt `ekko-task`. De holdes utenfor sammenligningen, men tas med i totalen.

## 5. Dashboard innen to minutter og kontroll mot full omberegning

- **Måling:** For hver batchjobb som blir `completed` i den representative perioden sammenlignes `extraction_jobs.completed_at` (resultatene er lagret) med `max(agg_daily.updated_at)` for tenantens lokale datoer i batchen. Målet er median og 95-persentil ≤ 120 s ved representativ last. Leverandørens behandlingstid (`completed_at − submitted_at` minus vår skrivetid) måles og rapporteres separat og teller ikke mot målet.
- **Mot full omberegning:** For én tenant og dato tas en kopi av radene i `agg_daily` og `agg_source_daily` (uten `id` og tidsstempler), sortert som i `WorkChainTest`, og hashes. Kjør deretter `php artisan ekko:aggregate <dato> --sync --tenant=<id>` i `ekko-task` og hash på nytt. Hashene skal være like. Gjør det samme for `usage_monthly` med `ekko:usage-rollup`.
- **Ingen doble forbruksføringer:** `usage_events` skal ikke ha mer enn én rad per kilde og type i perioden. Grupper på kildekolonnene og forvent 0 dubletter. Kvotevarsler per terskel skal ikke telles dobbelt.
- **Ingen tapte oppgaver:** ingen `runs` står `collected` i mer enn 1 time uten batchjobb, ingen `work_signals` venter i mer enn 1 time eller er stoppet, og ingen `extraction_jobs` står `submitted` i mer enn 25 timer.
- **Ingen tenant-lekkasje:** `composer test:isolation` er grønn på den utrullede committen. Web-loggens `work.wake` inneholder bare ID-er, og ingen `unknown` kommer fra legitime oppgaver (bare fra syntetiske tester).

## 6. Kostnadsvurdering

**Prisgrunnlag (estimat, kontrolleres mot [Cloud Run-prisene](https://cloud.google.com/run/pricing) og [Cloud Tasks-prisene](https://cloud.google.com/tasks/pricing) på vurderingstidspunktet):** Cloud Run-jobber faktureres per instans for hele levetiden, med minimum ett minutt per instans (spec 17.3). Instansbasert pris er om lag 0,000018 USD per vCPU-sekund og 0,000002 USD per GiB-sekund i tier 1. Gratiskvoten er om lag 180 000 vCPU-sekunder og 360 000 GiB-sekunder per måned og faktureringskonto. Cloud Tasks har om lag 1 million gratis operasjoner per måned, deretter om lag 0,40 USD per million. Web faktureres per forespørsel.

**Teoretisk nedre grense ved tomgang (estimat, ikke målt):**

| Kilde | `legacy` (i dag) | `event` |
|---|---|---|
| `extraction-poll` → `ekko-wake` | 96 starter/døgn × ≥ 60 s ≈ 5 760 vCPU-s/døgn | 0 |
| `aggregate` + `usage-rollup` → `ekko-task` | 48 starter/døgn × ≥ 60 s ≈ 2 880 vCPU-s/døgn | 0 |
| `work-recover` (web) | – | 24 forespørsler/døgn, noen hundre ms hver |
| Cloud Tasks | 0 | 0 ved tomgang |

Med 1 vCPU / 1 GiB tilsvarer `legacy`-grensen om lag 259 000 vCPU-s og like mange GiB-s per 30 dager. Det overstiger CPU-gratiskvoten alene, før faktisk kjøretid og andre jobber. Etter gratiskvoten utgjør det i størrelsesorden 1–5 USD i måneden (*estimat*). Beløpet er lite. Det viktigste utbyttet er færre unødige starter og raskere dashboard, ikke en stor besparelse. Det skal ikke påstås noen netto besparelse før tallene fra avsnitt 1, 3 og 4 er målt for samme volum.

**Ved arbeid (estimat):** Første vekking etter tomgang gir én `ekko-wake` og én `ekko-worker`, som én `extraction-poll`-runde i dag. Deretter tar den aktive workeren arbeidet. Hver vekking koster én oppretting og typisk 2–5 leveringsforsøk i Cloud Tasks (425 til arbeidet er tatt), altså langt under gratiskvoten ved dagens volum. Statuskall mot leverandøren følger batchens alder (60 s → 900 s) i stedet for `poll_delay_seconds` i PollExtractionBatch. Sammenlign det målte antallet.

**Vurdering:** Fyll inn tabellen under med målte tall for tomgang og representativ periode i `legacy`, `shadow` og `event`, og regn ut kostnad med prisene på vurderingstidspunktet. Konklusjonen skrives bare fra målte tall.

## 7. Tilbakerulling

- **Kodenivå (testet):** `WorkChainTest` «rollback: …» viser at kjeding av og køpubliseren tilbake midt i en innsending verken mister arbeid eller sender en batch to ganger. `WorkWakeEndpointTest` viser at ruten fortsatt tømmer leveringer som er ute.
- **Produksjon:** følg runbookens «Akutt stopp og tilbakerulling». Trinnet settes tilbake til `ready` med `-var=allow_work_rollout_downgrade=true` i den ene gjennomgåtte planen. Planen skal bare vise miljøvariablene tilbake (`EKKO_WORK_CHAINING=false`, `EKKO_WORK_PUBLISHER=queue`), de tre planene `paused=false` og etiketten `ready`. Kontroller deretter avsnitt 5, punktet om tapte oppgaver, og at det ikke finnes doble batcher (avsnitt 3 punkt 5).
- **Øvelse:** Eieren avgjør om tilbakerullingen skal øves i produksjon før `event`. Hvis ja, gjøres det i en tom periode fra `shadow` til `ready` og tilbake, med målingene over.

## 8. Opprydding

- Syntetiske Cloud Tasks-oppgaver forsvinner etter 2xx. Kontroller med `gcloud tasks list --queue=ekko-work --location=europe-west1` at ingen `t40f4-synthetic-*` står igjen, og slett dem eventuelt med `gcloud tasks delete`.
- En egen testtenant for målingen stenges og slettes etter T39-F6 (`ekko-tenant-purge` etter 30 dager, eller manuelt etter runbooken).
- Plan- og utskriftsfiler i `/tmp` slettes. Ingen midlertidige IAM-grants eller operatørroller skal stå igjen. Kontroller IAM som i trinn 2.
- Board: legg bevis i oppgaven som `status` (≤ 500 tegn, ingen kundedata) med lenke til denne fila. Oppgaven lukkes først etter merge og dokumentert aksept.

## Bevis

*Fylles ut under fase B.*

| Periode (UTC) | Trinn | wake-starter | worker-starter | task-starter (agg/usage) | Fakturert vCPU-s jobber | Web vCPU-s | DB-transaksjoner | Cloud Tasks opprett/forsøk | Statuskall | Dashboard p50/p95 |
|---|---|---|---|---|---|---|---|---|---|---|
| | legacy, tom | | | | | | | 0/0 | | – |
| | legacy, representativ | | | | | | | 0/0 | | |
| | shadow, tom | | | | | | | | | – |
| | shadow, representativ | | | | | | | | | |
| | event, tom | | | | | | | | | – |
| | event, representativ | | | | | | | | | |
