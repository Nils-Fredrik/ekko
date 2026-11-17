# Driftsrunbook

## Overvåking og hendelser (T36)

Varslene går til plattformadmin på den godkjente e-postkanalen. Terraform i
`infra/terraform/monitoring.tf` eier åtte policyer, én varslingskanal og én
loggmetrikk. `ekko-monitor-health` kjører `ekko:monitor-health` i `ekko-task`
hvert 15. minutt, uavhengig av `enable_schedules`. Kontrollen kaller ingen
leverandører og starter ingen innsamling. `enable_monitoring_schedule` skal først
slås på når kommandoen finnes i det utrullede jobbimaget.

| Varsel | Terskel | Første kontroll |
|---|---|---|
| Web | HTTP 5xx / alle svar >2 % i et rullerende 10-minuttersvindu | Revisjon, feiltype og nylig deploy; vurder tilbakeføring under |
| Jobb | Minst én feilende Cloud Run Job-eksekvering | Eksekvering, exitkode, vaskede logger; ikke gjenta en delvis deploy blindt |
| Motor | >20 % `failed` av `collected`, `extracted`, `failed` siste 24 timer, per motor | Leverandørstatus, pris, autentisering og kvote; ikke aktiver rå HTTP-logging |
| Innsamling | Batch planlagt for >6 timer siden har fortsatt `queued`, `running` eller `submitted` | Scheduler, workerstart, kø og reservasjoner; ferdige kjøringer varsler ikke selv med gamle batchtellere |
| Uttrekk | `extraction_jobs.status=submitted` i >24 timer | Poll-schedule, leverandørbatch og feilkrok; ikke send samme batch på nytt uten å kontrollere eksisterende resultat |
| SQL CPU / disk | >80 % kontinuerlig i 30 minutter | Kapasitet, tilkoblinger og aktive spørringer; ikke slett data som første tiltak |
| Helsekontroll mangler | Ingen fullført kontroll i 45 minutter | Scheduler og `ekko-task`; varslet aktiveres etter første registrerte måling |

Cloud Monitoring kan bruke noen minutter på å ta inn nye målinger. Web-raten
summeres på tvers av revisjoner og statuskoder før forholdstallet beregnes.
Kvotestopp teller ikke som motorfeil. Motorandelene vektes etter antall kjøringer,
ikke som gjennomsnitt av tenantprosenter. Aktive og pausede tenants tas med;
lukkede tenants tas ikke med. Hver tenant leses i sin egen RLS-transaksjon.
En feil under lesing avbryter kontrollen; ingen delvis «frisk» status eller
fullført-heartbeat publiseres.

`monitoring.issue` inneholder `context.signal`, tellinger og eventuelt intern
`engine_key`, aldri svar, prompts, navn, tokens eller leverandørfeilmeldinger.
Loggvarslene begrenses til én melding i timen per policy og lukkes automatisk
etter en time uten nye treff. Se ferske logger før hendelsen erklæres løst.
`monitoring.completed` viser at hele kontrollen fullførte. Motorstatus for T30
finnes i databasecachen `monitoring.engine_health`, med `checked_at` og
`engines[key]={total,failed,unhealthy}`, TTL 30 minutter. Manglende/utløpt cache
betyr ukjent status, ikke frisk. Verdien er bare til plattformadmin og må ikke
sendes i tenantens delte Inertia-props.

Ved hendelse:

1. Noter tidspunkt, policy, revisjon/eksekverings-ID og intern signaltype.
2. Kontroller siste CI/deploy før ny utrulling eller omkjøring. Bevar fungerende
   trafikkfordeling. Sjekk Scheduler og jobbstatus før leverandøren feilsøkes.
3. Avgrens Cloud Logging til `ekko-web` eller riktig jobb og tidsrom. Bruk interne
   ID-er; ikke kopier kundedata eller rå unntak til Board.
4. Gjør ett avgrenset tiltak og verifiser med ny kontroll/røyktest. Noter årsak,
   tiltak og resultat på Board. Kritiske brukerfeil prioriteres før oppsamlet kø.

Nyttige lesekommandoer (uten hemmelighetsverdier):

```sh
gcloud run services describe ekko-web --project=ekko-514612 --region=europe-west1 \
  --format='json(status.traffic,status.latestReadyRevisionName)'
gcloud run jobs executions list --job=ekko-task --project=ekko-514612 --region=europe-west1
gcloud scheduler jobs describe ekko-monitor-health --project=ekko-514612 --location=europe-west1
gcloud beta monitoring policies list --project=ekko-514612
gcloud sql operations list --instance=ekko-postgres --project=ekko-514612 --limit=10
```

### Aktivere og verifisere overvåkingen

Etter review/merge: sett `enable_monitoring=true` og den godkjente
`monitoring_email` i lokal, ignorert tfvars. Start med
`enable_monitoring_schedule=false`. Kontroller lagret Terraform-plan før apply.
Hvis en full plan inneholder eldre endringer fra andre oppgaver, bruk en ny plan
avgrenset til de åtte `google_monitoring_alert_policy`-ressursene,
`google_monitoring_notification_channel.operations`,
`google_logging_metric.monitoring_heartbeat` og
`google_cloud_scheduler_job.monitoring`. For T36 skal denne planen bare opprette
11 ressurser. Den skal ikke endre web, jobbimages, SQL, hemmelighetsgrants eller
innsamlingsplaner. Full drift registreres separat og er ikke skjult av den
avgrensede kontrollen.

Når jobbimaget med kommandoen er utrullet, sett `enable_monitoring_schedule=true`,
kontroller en ny avgrenset plan og apply. Kjør Scheduler manuelt én gang og
kontroller en vellykket eksekvering og en fersk `monitoring.completed` før
fraværsvarslet regnes som operativt. Kontroller at alle ni innsamlingsplaner
fortsatt har sin tidligere pause-status. Verifiser også e-postkanalen i Cloud
Monitoring; mottatt varsel må bekreftes av mottakeren ved en kontrollert test.

### Backup og gjenoppretting

Øvelsesbevis: [T36 – gjenoppretting](t36-recovery-evidence.md).

Produksjon har PostgreSQL 16 i `europe-west1`, daglig backup med sju beholdte
kopier og PITR med sju dager transaksjonslogger. CI tar i tillegg en on-demand
backup før migrering. Kontroller faktisk backupstatus; konfigurasjon alene er
ikke bevis på en gjenopprettbar kopi. Råsvar i GCS har objektversjonering;
gjenoppretting av SQL ruller ikke tilbake GCS eller utsendte meldinger.

Gjenopprett først til en ny instans. En feil i `--restore-instance` kan overskrive
produksjonen: mål skal alltid ha prefikset `ekko-restore-t36-` i øvelsen. Bruk
samme region, PostgreSQL-versjon og minst samme diskstørrelse. Ingen tjeneste,
Scheduler eller jobb skal kobles til kopien. Den inneholder produksjonsdata og
skal bare nås via connector med IAM og TLS; aldri åpne et autorisert IP-nett.

```sh
# Velg en SUCCESSFUL backup og noter ID, sluttid og kilde.
gcloud sql backups list --instance=ekko-postgres --project=ekko-514612 --limit=10
# Velg et ubrukt navn; stopp hvis det allerede finnes.
ekko_restore_id="ekko-restore-t36-$(date -u +%Y%m%d%H%M%S)"
gcloud beta sql instances create "$ekko_restore_id" --project=ekko-514612 \
  --region=europe-west1 --database-version=POSTGRES_16 --edition=ENTERPRISE \
  --tier=db-g1-small --storage-type=SSD --storage-size=20 \
  --connector-enforcement=REQUIRED --ssl-mode=ENCRYPTED_ONLY \
  --no-backup --no-deletion-protection --no-final-backup \
  --labels=purpose=restore-drill,task=t36
# Erstatt BACKUP_ID med den kontrollerte vellykkede backupen.
gcloud sql backups restore BACKUP_ID --backup-instance=ekko-postgres \
  --restore-instance="$ekko_restore_id" --project=ekko-514612
python3 scripts/operations/verify-restore.py --instance "$ekko_restore_id"
```

Verifikatoren krever et midlertidig navn, riktig merking/region, påkrevd connector
og TLS, ingen autoriserte nettverk og RUNNABLE status. Den starter en lokal
Cloud SQL Auth Proxy og leser migreringspassordet fra Secret Manager bare i
minnet til `psql`-prosessen. Passord og spørringsresultater skrives ikke ut.
Den sjekker migreringer, tenanttabellenes FORCE RLS/policyer, den begrensede
app-rollen og migreringsrollen. Syntetiske rader beviser ingen tilgang uten
valgt tenant og korrekt tilgang for to forskjellige tenants. Hele proben
rulles tilbake; ingen faktiske kunderader leses.

Når kontrollen er bestått (også etter en feilet øvelse): kontroller navn og
`purpose=restore-drill` igjen, slett bare øvelsesinstansen, vent til slettingen er
ferdig og bekreft at `ekko-postgres` fortsatt er RUNNABLE. Bruk aldri et navn fra
ukontrollert loggtekst som mål. Stopp lokale proxyprosesser etterpå.

```sh
gcloud sql instances describe "$ekko_restore_id" --project=ekko-514612 \
  --format='json(name,settings.userLabels)'
gcloud sql instances delete "$ekko_restore_id" --project=ekko-514612
gcloud sql instances list --project=ekko-514612 --format='table(name,state)'
```

Ved en reell gjenoppretting må eieren velge gjenopprettingstidspunkt og godta
data som går tapt mellom tidspunktet og hendelsen. Pause Scheduler, stans nye
skrivinger og la pågående arbeid stoppe kontrollert. Verifiser kopien, roller,
aktuell `APP_KEY`/tidligere nøkler, GCS-tilgang og bakoverkompatible migreringer.
Bytt deretter databaseforbindelsen for både web og alle jobber som én koordinert
endring, kjør autentisert røyktest uten trafikk, og flytt trafikk først etter grønt
resultat. Vurder duplikatfare i kø, leverandørbatcher, rapporter og varsler før
Scheduler startes igjen. Ikke send lagrede køjobber ukritisk på nytt.

### Nøkkelrotasjon

Før rotasjon: identifiser nøyaktig forbruker, hemmelighetsreferanse og gjeldende
versjon uten å lese verdien til terminalen. Hemmeligheter lagres som nye versjoner
i Secret Manager, aldri i Terraform, argumenter, PR eller Board. En beskyttet
inputfil eller stdin kan brukes med `gcloud secrets versions add --data-file`.
Secret Manager-miljøvariabler lastes når en ny instans starter; en ny versjon
alene oppdaterer ikke kjørende Cloud Run-revisjoner.

| Hemmelighet | Rekkefølge og kontroll |
|---|---|
| Leverandørnøkkel | Opprett ny leverandørnøkkel med riktig prosjekt/region og begrensninger. Legg til hemmelighetsversjon, oppdater jobbkonfigurasjon og gjør et godkjent lavkost-kall. Tilbakekall gammel nøkkel først når gamle eksekveringer er ferdige. Egne tenantnøkler roteres via eksisterende nøkkelgrensesnitt. |
| Google OAuth-klienthemmelighet | Overlapp gamle/nye nøkler der leverandøren tillater det. Ny Secret Manager-versjon, ny web-kandidat uten trafikk, test innlogging og avvisning, promoter, tilbakekall gammel etter kontroll. Behold klient-ID og eksakte redirect-URL-er. |
| Databasepassord | Planlegg et vedlikeholdsvindu med pauset kø/Scheduler og stans i nye skrivinger. Endre kun den tiltenkte SQL-rollen og dens hemmelighetsversjon, utrull alle forbrukere med ny verdi, og verifiser innlogging/RLS/røyktest før trafikk og jobbkjøring gjenopptas. SQL og Secret Manager endres ikke atomisk; gammel revisjon kan ikke brukes som rollback uten samsvarende SQL-passord. App- og migreringsrollen er separate. |
| `APP_KEY` | Ikke erstatt blindt: gamle krypterte verdier trenger gammel nøkkel. Laravel støtter `APP_PREVIOUS_KEYS`, men dette er ikke montert i dagens Terraform. Før rotasjon må en egen reviewet endring montere gamle nøkler, teste dekryptering og planlegge rekryptering/utlogging. Behold nødvendige nøkler så lenge backups som krever dem beholdes. Ikke slett gammel nøkkel bare fordi web starter. |
| GitHub/WIF | WIF har ingen statisk GCP-nøkkel å rotere. Revider trust-betingelser og IAM. Roter review-App-nøkkel og tokens i riktig GitHub environment, bekreft både positiv/negativ tilgang og ny kryssreview før gammel nøkkel tilbakekalles. |

Ved mistanke om kompromittering prioriteres tilbakekalling og avgrensing fremfor
overlapping. Noter operasjon og versjonsnummer, aldri selve verdien. Kontroller
sekundære kopier, aktive revisjoner og backupbehov før en hemmelighetsversjon
ødelegges permanent.

Kilder: [Cloud SQL restore](https://docs.cloud.google.com/sql/docs/postgres/backup-recovery/restoring),
[Cloud Run-metrikker](https://docs.cloud.google.com/monitoring/api/metrics_gcp_p_z),
[Cloud SQL-metrikker](https://docs.cloud.google.com/monitoring/api/metrics_gcp_c).


## Applikasjonslogger (T39-F7)

Web og alle jobbimages bruker `stderr` med Monologs `GoogleCloudLoggingFormatter`. Terraform setter `LOG_LEVEL=info`; kanalen har også `info` som standard. `severity` og `time` tolkes av Cloud Logging. Interne identifikatorer finnes under `jsonPayload.context.tenant_id`, `user_id` og `run_id`; `null` betyr at operasjonen ikke har den identifikatoren. En samlekjøring kan ha flere tenants og får derfor først tenant-ID inne i hvert tenantscope. En tenantjobb arver ikke brukeren som startet den. En innsamlingsjobb har sin interne run-ID.

Ved `Application exception.`: bruk `jsonPayload.context.exception.type`, `file`, `line`, `trace` og `causes` sammen med ID-ene og revisjonen i Cloud Run. Feltet `causes` inneholder bare klassenavn. Ingen unntaksmeldinger, SQL/bindings, e-post, leverandørsvar eller stackargumenter skal ligge i posten. Ikke aktiver rå unntakslogging for å feilsøke produksjon; gjenskap feilen med syntetiske data lokalt. Lokale Artisan-feil beholder vanlig konsollvisning. I produksjon skriver Artisan en fast feiltekst etter den vaskede loggposten.

Avgrens for eksempel Logs Explorer til `resource.type="cloud_run_revision"`, `resource.labels.service_name="ekko-web"`, `severity>=ERROR` og en intern `jsonPayload.context.tenant_id`. Jobber bruker `resource.type="cloud_run_job"`. Filtrer også på tidspunkt/revisjon; bruk ikke kundedata som søkeeksempler i Board eller PR-er.

Testene i `tests/Feature/Logging` dekker SQL, SMTP-debug, innpakkede feil, request-/jobbkontekst og feil etter scope-opprydding. Containerkontrollen kjører en syntetisk feilende Artisan-kommando i begge ferdigbygde images, verifiserer JSON/severity og `zend.exception_ignore_args=On`, og avviser lekkede testmarkører. T36 bygger videre på disse feltene for overvåking. Dette er ikke bevis på at T36-varsler er aktivert.

## Klientens IP-adresse bak Cloud Run (T39-F3)

Med HTTPS-lastbalansereren har `ekko-web` `TRUSTED_PROXIES=REMOTE_ADDR,35.190.63.68`, generert fra Terraform-IP-en. Laravel erstatter `REMOTE_ADDR` med adressen som faktisk kobler til før Symfony tolker kjeden. Både Cloud Runs front og den faste lastbalansereren er dermed betrodd, uten å gjette en intern proxyadresse eller et antall ledd. Host, port og prefiks er aldri betrodd. Bare konfigurasjoner uten lastbalanserer bruker `*`.

- **Verifiser etter første utrulling:** to forespørsler fra ulike nett skal gi ulik `ip()` i loggen, og ti feilede SSO-forsøk fra ett nett skal ikke gi 429 fra et annet.
- **Ved endret proxykjede:** test både direkte `run.app` og domenet med forfalskede innledende adresser, inkludert en falsk lastbalansereradresse. `*` alene ga lastbalansererens IP for alle på domenet i live-testen 16.11.2026. Sammenlign appens sesjons-IP med Googles forespørselslogg for hver vei; klienten kan bruke IPv6 direkte og IPv4 via domenet, så ulike adresser er ikke alene en feil. Registrer bare testresultatet på Board, ikke klientens IP eller sesjonsdata.
- **Ved IPv6 på lastbalansereren:** legg også den nye forwarding-adressen inn i `TRUSTED_PROXIES` og gjenta kontrollen. Dagens ALB har bare IPv4; direkte IPv6-trafikk til Cloud Run går ikke gjennom et eget ALB-IPv6-ledd.

## Databaserollen ved oppstart (T39-F2)

- I produksjon feiler `/up`, køarbeideren og `ekko:*`-kommandoene hvis innloggingsrollen (`DB_USERNAME`) kan omgå RLS. Det gjelder en superbruker, en rolle med `BYPASSRLS`, `CREATEROLE` eller `CREATEDB`, et medlem av `cloudsqlsuperuser` eller av en annen privilegert rolle, og en rolle som eier tenanttabellene. Feilen `Unsafe database login role` i loggen sier hva som er galt, for eksempel at `DB_USERNAME` peker på `ekko_migrate`.
- Er databasen utilgjengelig ved oppstart, feiler sjekken også, med en databasefeil (`QueryException`) i stedet. Sjekk da tilkoblingen før rollen.
- Rett `DB_USERNAME` til appens rolle (`ekko_app`). Ikke slå av sjekken med `DB_VERIFY_LOGIN_ROLE=false`.
- En ny `ekko-web`-revisjon med feil rolle blir ikke klar, fordi `/up` er oppstartsproben. Trafikken blir værende på forrige revisjon.

## Stenging og sletting av tenant (T39-F6)

- «Slett tenant» i teaminnstillingene stenger tenanten. Den slettes ikke. Brukerne mister tilgang med en gang, og `closed_at` settes.
- Jobben `ekko-tenant-purge` (Cloud Scheduler, kl. 03.30) kjører `php artisan ekko:purge-closed-tenants` i `ekko-task`. Den sletter tenanter stengt for minst 30 dager siden, først råsvarene i GCS og så databasen.
- Feiler GCS-slettingen, blir tenanten stående, og jobben feiler. Neste kjøring prøver igjen. Se `tenant.purged` og feil i Cloud Logging.
- Skal en stengt tenant gjenåpnes før sletting, sett `status` tilbake og `closed_at` til `null` i databasen.

## Kryssreview i GitHub (T42)

### Integrasjoner og identiteter

- Claude GitHub App skal være installert for `Nils-Fredrik/ekko`. `CLAUDE_CODE_OAUTH_TOKEN` kommer fra et eget Claude Team-sete, aldri fra Console-organisasjonens API-nøkkel. Workflowen bruker eksplisitt `claude-opus-5-5` og en commit-pinnet `anthropics/claude-code-action`.
- GitHub-repoet må være koblet til ChatGPT Codex, med kodereview aktivert. Eieren bekreftet 15.11.2026 at repoets innstillinger ikke har modellvalg; GitHub-kvitteringene oppgir heller ikke modell. GPT-6.1 Sol kan derfor ikke bekreftes. Aktiveringen bruker integrasjonens leverandørstyrte modell som dokumentert avvik i spec 19.5 (Board #457). Workflowen kan utløse review med `@codex review`, men kan ikke velge modell gjennom en dokumentert kommentarparameter. Verifiser bot-identitet, aktuell head-SHA og fersk kvittering; ikke anta modellidentitet. Kontroller modellvalget igjen hvis integrasjonen senere tilbyr det.
- `CODEX_TRIGGER_TOKEN` er en separat, minst privilegert finmasket GitHub-token for å kommentere på PR-er i dette repoet. Den trenger Pull requests: read and write på bare ekko (Metadata: read følger automatisk). Issues: write alene ga 403 på PR-kommentarer i live aksepttesten. Den brukes bare av prepare-steget og eksponeres aldri for review-agenten. Kontroller at tokenets avsender faktisk kan utløse Codex; en kommentar fra `GITHUB_TOKEN` alene er ikke tilstrekkelig testbevis.
- `BOARD_MCP_TOKEN` er en dedikert CI-identitet med tilgang til dette Board-et. Den må virke som Bearer-autentisering mot `https://board.maksimer.com/mcp`. En nettleserinnlogging er ikke en CI-hemmelighet. Klienten initialiserer MCP, leser snapshot og poster bare en kort blocker med PR-referanse etter tre ulike mislykkede kode-revisjoner. Ingen diff, modelltekst eller hemmeligheter sendes til Board.

### Separat godkjenningsidentitet

Opprett en dedikert GitHub App for `agent-review`, installert bare på `Nils-Fredrik/ekko`. Den trenger Contents: read, Pull requests/Commit statuses/Issues: write. Sett offentlige `AGENT_REVIEW_APP_ID` og `AGENT_REVIEW_BOT_ID` til App-ID og dens bot-bruker-ID (ulike tall). Den delte GitHub Actions-appen/boten godtas ikke.

Opprett environment `agent-review` med **Selected branches and tags**, nøyaktig én regel av typen branch: `main`. Ikke bruk «Protected branches only» eller `refs/pull/*/merge`. Legg `AGENT_REVIEW_PRIVATE_KEY`, `CODEX_TRIGGER_TOKEN` og `BOARD_MCP_TOKEN` bare her, aldri som repo-/organisasjonshemmeligheter tilgjengelig for PR-workflows. `pull_request_target` bruker main-refen; en vanlig PR-workflow bruker pull-refen og får dermed ikke nøkkelen. Verifiser dette i en negativ test-PR. Bare request/prepare/gate får dette miljøet; Claude-jobben får det ikke.

Kortlivede App-tokens opprettes med den pinnede `actions/create-github-app-token` for hvert jobbtrinn og tilbakekalles av actionen. Gate-ventingen er begrenset til 45 minutter, under tokenets levetid på én time. Påkrevd `agent-review` må bindes til denne App-ID-en i grenbeskyttelsen; en ubundet status er ikke akseptabel.

### Hva sjekken kontrollerer

`agent-review.yml` («Agent review request») kjører på `pull_request_target`, sjekker bare ut betrodd base og setter status til pending. `agent-review-execute.yml` starter deretter på `workflow_run` og bruker bare sin betrodde main-commit (`github.sha`). `source.mjs` verifiserer kildekjøringens repo, workflow-ID/sti, event, vellykket resultat og forsøksnummer via GitHub API. Det må finnes nøyaktig én PR med samme head som kildekjøringens uforanderlige head-SHA. Gjeldende PR-base må være execution-jobbens betrodde checkout. Hvis main ble oppdatert mens request ventet, tas et nytt snapshot av denne basen og begge nødvendige reviewere kjører på nytt. Foreldet head, lukket PR eller utkast stopper før reviewer og App-token opprettes. Et workflow-navn alene er ikke tilstrekkelig: PR-kode kan lage en workflow med samme navn. Ingen artifacts fra kildekjøringen lastes ned. PR-kode kjøres aldri. Diff og PR-beskrivelse hentes som data; endret head, base, merge-commit, tittel eller draft-status underveis gjør sjekken rød. Filer som er flyttet fra følsomme stier regnes fortsatt som følsomme. Feil eller trunkert filliste gir aldri fritak fra review.

Claude kjører i restricted-modus og har bare Read/Glob/Grep avgrenset til arbeidsmappen og ett avgrenset MCP-verktøy for å publisere sin konklusjon på den aktuelle PR-en som Claude App. Verktøyet binder repo, PR og revisjon til workflowens kontekst; modellen kan ikke velge URL eller mottaker. Diff og kontekst ligger i `.review/` i arbeidsmappen. `--restricted` sperrer filer utenfor arbeidsmappen; `/proc`, `/tmp` og `.git` er i tillegg eksplisitt avvist. Ikke bruk `Read(~/**)` som deny: GitHub-runnerens repo ligger også under hjemmemappen. Tillat verktøynavnene Read/Glob/Grep; Glob(path)/Grep(path) avgrenser ikke filer, mens restricted håndhever arbeidsmappen. Publiseringsverktøyet avviser kjente tokenmønstre og verdier på minst 16 tegn fra miljøvariabler med TOKEN, SECRET, KEY, PASSWORD eller CREDENTIAL i navnet i oppsummeringen. Ingen shell- eller filskriveverktøy er tilgjengelige. Publiseringsverktøyet returnerer hele objektet med conclusion, summary og comment_id, som revieweren skal kopiere uendret til sitt strukturerte resultat. Verdict-steget validerer fortsatt at strukturert konklusjon og oppsummering er identiske med kommentaren fra bot-ID 209825114, på riktig PR og full head-SHA. `track_progress` er av. Eksplisitt prompt bruker agent-modus på workflow_run og beholder main-checkout. Claude får fortsatt sitt eget App-token fra Anthropic; vi erstatter det ikke med den delte GitHub Actions-identiteten.

Codex krever bot-ID 199175422, en oppdatert `codex-review-state:v1` med full head-SHA, fullført kodereview uten funn, og en ny `codex-review-receipt:v1` etter bestillingen. Kun oppdatert sticky-kommentar er utilstrekkelig: den kan også oppdateres av en sikkerhetsreview. Dette er et observert integrasjonsformat, ikke en offentlig garantert API. Formatendring, manglende kvittering eller flere statuskommentarer blokkerer. Reaksjoner eller menneskers «GODKJENT» brukes ikke som reservegodkjenning.

Statusen skrives til aktuell head-SHA av den dedikerte App-en. Head, base og test-merge-SHA beholdes i snapshotkontrollen. `main` må derfor kreve oppdatert base (`strict: true`). Tre ulike mislykkede head-SHA-er siden siste godkjenning utløser Board-blocker på T42, med lenke til den berørte PR-en. Omkjøring eller tittelredigering på samme head teller ikke som nye runder. Eieren vurderer videre retting; workflowen merger aldri. Manglende Board-autentisering gjør gaten rød, og feil ved eskalering gir ingen godkjenning. Ved feil etter vellykket Board-post men før lagret rundekvittering kan en duplikat forekomme; PR-referansen gjør den gjenkjennelig.

Den gamle automatiske `claude-code-review.yml` erstattes av denne workflowen. `claude.yml` for eksplisitt `@claude` beholdes. Slå av overlappende automatiske Codex-utløsere dersom de skaper duplikater, og verifiser den eksplisitte triggeren før aktivering.

### Kontrollert aktivering

1. Valider review-koden, CI, secrets og integrasjoner. Eieren merger T42 etter uavhengig kryssreview; Begge workflows må finnes på main før ende-til-ende-testen kan kjøre. En PR som retter selve oppstartsfeilen vurderes med eksplisitt uavhengig review og grønn test-CI før eierens merge; en rød automatisk oppstartssjekk er ikke et godkjent review. Utkastet er ikke bevis på aktiv beskyttelse.
2. Opprett tre små test-PR-er: en ufølsom `[Codex]`, en ufølsom `[Claude Code]`, og en følsom endring. Kontroller henholdsvis Claude, Codex og begge. Gi alle beskrivelse med Board-oppgave og akseptkriterier. Bruk ingen kundedata. Ta med en push fra en av de tillatte botene `claude`, `chatgpt-codex-connector` eller `cursor`; kontroller at review-jobben faktisk starter. Lukk test-PR-ene uten merge.
3. Kontroller synlig Claude-botkommentar, strukturert konklusjon, fersk Codex-kvittering, korrekt head/merge-SHA og grønn `agent-review`. Test en ny commit og en endret base: gammel godkjenning må ikke godta ny kode. Test tre mislykkede runder og kontroller én Board-blocker.
4. Først når testene fungerer, aktiver grenbeskyttelse på `main`: krev `test` og `container build` fra GitHub Actions (App-ID 15368), samt `agent-review` (bundet til `AGENT_REVIEW_APP_ID`), oppdatert base, håndheving også for administratorer, og ingen force-push eller sletting. Behold eventuelle strengere eksisterende regler. Ikke sett krav om menneskelig egen-PR-godkjenning som eieren ikke kan oppfylle; agent-godkjenning håndheves av `agent-review`.
5. Hent metadata uten å endre noe og kontroller også at PR-workflows ikke får App-nøkkelen:

```sh
gh api repos/Nils-Fredrik/ekko/branches/main/protection > /tmp/ekko-protection.json
gh api repos/Nils-Fredrik/ekko/environments/agent-review > /tmp/ekko-review-environment.json
gh api repos/Nils-Fredrik/ekko/environments/agent-review/deployment-branch-policies > /tmp/ekko-review-policies.json
jq -n --argjson app_id "$AGENT_REVIEW_APP_ID" \
  --slurpfile protection /tmp/ekko-protection.json \
  --slurpfile environment /tmp/ekko-review-environment.json \
  --slurpfile policies /tmp/ekko-review-policies.json \
  '{app_id: $app_id, protection: $protection[0], environment: $environment[0], policies: $policies[0]}' \
  | node scripts/agent-review/check-protection.mjs
```

6. Dokumenter test-PR-er, revisjoner, CI og beskyttelseskontroll på Board. T42 lukkes først når hele aksepten er bekreftet. Inntil generell køaktivering merger eieren, eller GitHub med auto-merge eieren har aktivert per PR. Etter kontrollert aktivering kan mergekøen merge etter de samme beskyttelseskravene (ADR 0026).

### Akseptbevis fra 15.11.2026

Etterkontroll 17.11.2026 ved retting av PR #68: GitHub-metadata bekrefter nå også `container build` bundet til App 15368, i tillegg til `test` og `agent-review`. Dette erstatter ikke live aksept av den generelle mergekøen, som fortsatt kreves før aktivering.

Historisk aksept 15.11.2026 (før mergekø-utvidelsen): `main` krever `strict: true`, `test` fra GitHub Actions (App-ID `15368`) og `agent-review` fra Ekko Agent Review NF (App-ID `5773706`, bot-ID `357077641`). Kravene gjelder også administratorer. Force-push og sletting er av. `check-protection.mjs` består mot metadata hentet etter aktivering, inkludert miljøets eneste tillatte branch `main`.

Testene ble kjørt i to omganger: `88fac6b` etter #49 og `6f6ebdc` etter #54, som angitt per rad nedenfor. #54 endret bare Claudes lesetilgang og publiseringskvittering (samt tilhørende test/dokumentasjon). Codex-ruting, kildekontroll, eskalering, request-workflow og miljøregel var uendret. Derfor beholdes bevisene for disse kontrollene fra `88fac6b`, mens Claude- og dobbelreview ble kjørt på nytt mot `6f6ebdc`. De midlertidige PR-ene er lukket uten merge. Modellen bak Codex-integrasjonen er ikke eksponert; se avviket i spec 19.5 og Board #457.

| Kontroll        | Main/base           | Bevis                                                                                                                                                                                                                                                        |
| --------------- | ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Codex alene     | `88fac6b`           | PR #51, head `2220e3e`, review-kjøring `43756979343` og CI `43756955486` grønne.                                                                                                                                                                             |
| Claude alene    | `6f6ebdc`           | PR #57, head `16c29d3`, review-kjøring `43759435655` grønn; eksakt kvittering fra Claude-boten.                                                                                                                                                              |
| Begge reviewere | `6f6ebdc`           | PR #58, head `151dfeb`, review-kjøring `43759445560` og CI `43759420120` grønne.                                                                                                                                                                             |
| Bot-push        | `88fac6b`           | PR #50: `claude[bot]` pushet `aefd018`; request-kjøring `43757386759` startet automatisk.                                                                                                                                                                    |
| Ny kode         | `6f6ebdc`           | PR #57: `3d192dc` fikk pending og ingen arvet godkjenning; produksjonsfunksjonen `sameSnapshot` avviste den gamle godkjenningen. Fersk review `43759612562` og CI `43759585469` ble grønne.                                                                  |
| Endret base     | `88fac6b → 6f6ebdc` | PR #51s kildekjøring `43756955399` hadde base `88fac6b`. `reviewSource` mot ekte GitHub-data og main `6f6ebdc` avviste den med «Source does not match the trusted main checkout». Etter aktivering viste GitHub `BEHIND` til tross for grønne gamle sjekker. |
| Miljøsperre     | `88fac6b`           | PR #52, jobb `132943049815`: `refs/pull/52/merge` ble avvist av main-only-regelen før noen steg kjørte. Ingen hemmeligheter ble lest.                                                                                                                        |
| Eskalering      | `88fac6b`           | PR #50: tre ulike feilende heads ga Board #450; omkjøring `43757676130`, forsøk 2, ga ingen ny blocker. Testblockeren ble forklart og løst i #451.                                                                                                           |

Claude CLI 2.1.325 ble også testet med syntetiske filer: repo-lesing virket, mens `.git` og filer utenfor arbeidsmappen ble avvist. En separat CLI/MCP-rundtur brukte den ekte publiseringsfunksjonen med syntetisk API og viste identisk publisert og strukturert resultat. Ingen produksjonshemmeligheter ble brukt i disse lokale probene. De 24 deterministiske Node-testene består; funksjonstestene over erstatter ikke CI.

### Drift og stopp av mergekøen (T42, task:61)

**Registrert driftsstatus:** Køen ble kortvarig aktivert 17.11.2026. Første kjøring stoppet korrekt ved reviewfeil i PR #74, uten å endre noen PR. Den ble satt tilbake til `false` under kryssreview mens en manglende isolert test av grønn ny head med gammel kvittering ble supplert. Se [akseptbevis](t42-merge-queue-evidence.md). Kontroller aktuell repo-variabel `EKKO_MERGE_QUEUE_ENABLED` for gjeldende av/på-status; dette avsnittet er historikk. Første App-merge og vellykkede produksjonsdeploy gjenstår, og task:61 holdes åpen til begge er dokumentert.

**Standard og oppsettskrav:** En ny installasjon er deaktivert når `EKKO_MERGE_QUEUE_ENABLED` ikke er `true`. Følg [ADR 0026](decisions/0026-automatisk-mergeko.md) for App, main-avgrenset miljø, tillatte forfattere og isolert aksept før aktivering. Grenbeskyttelsen skal kreve `container build` og `test` fra App 15368 samt `agent-review` fra review-Appen, strict og administratorhåndheving. Metadataauditene må bestå. Det historiske review-akseptbeviset fra 15.11 dekker bare det opprinnelige oppsettet med to sjekker; den utvidede auditen og køaksepten er dokumentert separat i T42-beviset over. Etter godkjent kryssreview og merge av det supplerte beviset kan køen reaktiveres for kontroll av første ordinære App-merge og produksjonsdeploy.

Sett repo-variabelen `MERGE_QUEUE_BOT_SLUG` til kø-Appens eksakte slug uten `[bot]`, uten miljøoverstyringer. Review tillater denne boten i tillegg til de eksisterende. Køen verifiserer at tokenets App har samme slug før den endrer en PR. Isolert aksept må vise at akkurat denne Appens grenoppdatering starter både CI og Claude-review. Tom slug er tillatt for review før aktivering, men stopper køen.

Før aktivering kjøres også denne separate, lesende auditen av kømiljøet. Den opprinnelige auditen over dekker bare review-miljøet. Manglende miljø, jokertegn, tag-regler og ekstra grener skal feile:

```sh
gh api repos/Nils-Fredrik/ekko/environments/merge-queue > /tmp/ekko-queue-environment.json
gh api repos/Nils-Fredrik/ekko/environments/merge-queue/deployment-branch-policies > /tmp/ekko-queue-policies.json
jq -n --slurpfile environment /tmp/ekko-queue-environment.json \
  --slurpfile policies /tmp/ekko-queue-policies.json \
  '{environment: $environment[0], policies: $policies[0]}' \
  | node scripts/merge-queue/config.mjs audit-environment
```

Denne auditen må bestå etter at kømiljøet er opprettet; den er ikke del av det historiske review-akseptbeviset.

Actions-workflowen «Merge queue» skriver utfallet og PR-nummeret i kjøringssammendraget. Ved failure/conflict/review rettes PR-en i samme gren. Køen oppdaterer heller ikke røde eller ventende heads. Ved main-failure undersøkes siste CI/deploy før en ny merge. `native-auto-merge-pending` betyr at en PR med GitHubs auto-merge holder køen; også en ukvalifisert PR kan gjøre dette. Rett PR-en eller slå av dens native auto-merge.

Stopp ved å sette `EKKO_MERGE_QUEUE_ENABLED=false`. En pågående GitHub-operasjon kan fullføres. Native auto-merge per PR må eventuelt slås av separat; denne bryteren stopper bare den egne køen. Ukvalifiserte App-boter med NONE/CONTRIBUTOR får ikke unntak fra forfatterkravet, og feilende eller kansellerte sjekker fra andre Apps stopper også køen.

### Feil og gjenoppretting

Rettelsen i ADR 0027 skiller disse utfallene i `agent-review` og jobboppsummeringen:

| Utfall | Status | Tiltak |
|---|---|---|
| Alle påkrevde reviewere godkjenner | `success` | Vanlig mergeflyt med grønn CI |
| Verifiserte reviewfunn | `failure` | Rett funnene i samme gren; teller som faglig avslagsrunde |
| Claude-krasj, tidsgrense eller ugyldig kvittering | `error` | Les Claude-/verdict-loggen og kjør igjen etter retting; teller ikke som faglig avslag |
| Codex-kvittering mangler/har ukjent format etter 45 minutter | `error` | Kontroller integrasjonen og bestill ny review; ingen faglig avslagsrunde |
| Head/base/tittel eller reviewforespørsel endret | Ingen overskriving av nyere status; egen foreldet status kan bli `error` | Oppdater PR-grenen for ny request; gamle reviewkvitteringer kan ikke brukes |

Claude-steget har 20 minutters tidsgrense og jobben 25 minutter. Det finnes ingen `--max-turns`-grense: action v1.0.276 avviser ellers selv et fullført resultat når SDK-et rapporterer flere steg enn grensen. Dette ble observert ved 31, 36 og 42 steg. Ingen feil ignoreres med `continue-on-error`, og en publisert kommentar alene kan ikke erstatte vellykket jobb og eksakt strukturert kvittering. Etter merge av denne rettelsen må live-kjøring verifiseres; CI på PR-en tester koden, mens review-workflowen selv bruker eksisterende main.

Manglende eller utløpt token: roter den aktuelle GitHub-hemmeligheten og kjør workflowen igjen. Ikke senk godkjenningskravene for å få grønt. Ved endret Codex-format: hold sjekken rød, hent en ny anonymisert kvittering, oppdater parser og tester gjennom review. Ved endret base: oppdater PR-grenen og kjør ny review. En annullert kjøring eller formatfeil gir ingen godkjenning. Sjekk Board og PR før en ny runde etter eskalering.

Kilder kontrollert 15.11.2026: [Codex GitHub-integrasjon](https://learn.chatgpt.com/docs/third-party/github), [pinnet Claude-action og output-kontrakt](https://github.com/anthropics/claude-code-action/blob/f3b070c88125d33dbae5653df5f143101d4ea9dc/action.yml), [Claude CLI-verktøybegrensning](https://code.claude.com/docs/en/cli-reference).

Miljøreglene følger [GitHubs dokumenterte GITHUB_REF-kontroll](https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments). Repoets administrator kan fortsatt endre selve reglene; auditen og akseptbeviset må derfor lagres på Board.

`--restricted` er dokumentert i [Claude CLI-referansen](https://code.claude.com/docs/en/cli-reference) fra versjon 2.1.248. Bekreft installert CLI-versjon og MCP-miljøet i test-PR-en; ukjent flagg eller manglende miljø skal feile lukket. Prepare skriver `pending` på eventens head før API-oppslaget for mergeability, slik at en tittelendring ikke beholder gammel godkjenning ved timeout. Feil før prepare-scriptet (for eksempel utløpt App-nøkkel) krever retting og ny kjøring før eieren vurderer merge.

### Funn fra første live aktivering 15. november 2026

Test-PR #46–#48 bekreftet App-identiteten og at miljøet avviser `refs/pull/48/merge` før runner/steps starter. Kommentar-POST på PR krever Pull requests: write for både den dedikerte App-en og trigger-tokenet; Issues: write med Pull requests: read ga 403. Claude-actionens tokenbytte ga 401 «Invalid OIDC token» på pull_request_target, som i [upstream #713](https://github.com/anthropics/claude-code-action/issues/713). Derfor flyttes selve Claude-kjøringen til workflow_run med verifisert kilde. Den pinnede actionen støtter denne automation-hendelsen, men full live godkjenning må fortsatt testes etter eierens merge. Gamle workflow-kjøringer bruker gammel kode; opprett en ny testhendelse etter merge.

Etter #49 virket Claude OIDC på workflow_run, men `Read(~/**)` nektet alle filene under `/home/runner/work/ekko/ekko`. En ekte CLI-test med versjon 2.1.325 gjenskapte feilen under en syntetisk arbeidsmappe i hjemmemappen. Med den rettede regelen kunne Read lese testfilen; `.git` og filen utenfor arbeidsmappen ble fortsatt nektet. En separat CLI/MCP-test med den ekte publiseringsfunksjonen og en simulert HTTP-transport bekreftet at hele retur-objektet blir identisk med strukturert output. Den deterministiske testen avviser fortsatt et omformulert sammendrag. Ingen rettigheter til shell, skriving eller eksterne filer er lagt til.

## Utrulling og tilbakeføring

### Kjør en utrulling på nytt

Verified container artifacts expire after one day. If an artifact has expired,
rerun the entire CI workflow so that the images are rebuilt, checked and
transferred together; retrying only the deploy job cannot recover the artifact.
The image job has a 30-minute limit and deploy has a 90-minute limit. After a
timeout, inspect the backup, migration, traffic and job-image state before retrying:
termination may leave a migration or Cloud Run operation running independently.

### Tilbakefør webtrafikk

This procedure applies only after the deployment pipeline is completed and
approved for production. Identify a previously verified `ekko-web` revision
that is compatible with the current database schema. With the intended project
and region explicitly selected, run:

```sh
PROJECT_ID=ekko-514612 REGION=europe-west1 scripts/rollback-traffic.sh ekko-web-REVISION
```

Confirm that Cloud Run reports 100% traffic on the requested revision and check
application health. This moves web traffic only. It does not reverse migrations
or restore job images; assess and restore those separately if needed. Migrations
must remain backward compatible so that an earlier revision can run against the
current schema. Exercise this procedure in an isolated environment before use.

The private production exercise on 16 November 2026 confirmed that a failed
`gcloud run jobs update` can leave the rejected image in the job configuration.
After a partial deployment, inspect and restore **all three** application jobs
(`ekko-dispatch`, `ekko-worker`, `ekko-task`), including the job whose update failed.
Check each image and Ready condition before resuming schedules. Restoring web
traffic alone does not repair the worker. See the evidence in
[T10 deployment status](t10-deploy-draft.md).

## Produksjonsdomene og OAuth (T10)

Kanonisk URL er `https://ekko.maksimer.com`; Google-callback er `/auth/google/callback` på samme vert. Terraform klargjør IP, DNS-autorisert sertifikat og global ekstern lastbalanserer, med HTTP→HTTPS og TLS ≥1.2. DNS-postene og kontrollert bootstrapstatus står i [T10-status](t10-deploy-draft.md); oppsett og OAuth-prosedyre står i [Terraform-README](../infra/terraform/README.md).

Ved planlegging fra en ny arbeidskopi: kopier `production.tfvars.example` til ignorert `production.auto.tfvars`, behold `enable_https=true` og fyll inn eksisterende workload-valg. Standardverdien false må ikke brukes til å fjerne allerede provisionert HTTPS. Før offentlig åpning kontrolleres sertifikatstatus ACTIVE, domene/redirect, OAuth-innlogging for invitert konto og avvisning for uinvitert konto, samt klient-IP og spoofing både direkte og via lastbalansereren. Direkte kandidatadresser brukes fortsatt av røyktesten. Public IAM og schedules er separate brytere og skal fortsatt være av under bootstrap. Privat Google-test kan bekrefte appens tilgangskontroll, men en lokal headertransport kan miste IAM-token på nettleserens redirectkjede og gi Cloud Run 403 før appen nås. Slik 403 er ikke bevis på at Ekko avviste kontoen. Etter offentlig åpning gjentas hele innloggingen uten testtransport og uten manuell omlasting; dokumenter både uinvitert avslag og invitert dashboard.

Ved offentlig Google-aksept brukes en annen, uinvitert Google-konto til avslaget: den tidligere testkontoen har nå bruker og Google-kobling. Eieren forvalter den inviterte kontoen som en bevisst testidentitet med bare lesertilgang i testtenanten med null kvote; identifikatoren lagres ikke i repo/Board. Tilgangen gjennomgås ved avslutning av produksjonsaksepten. Invitasjonen i den private testen ble opprettet direkte som fixture; opprettelse og utsending via UI/e-post er ikke verifisert, og avhenger av e-postoppsettet i A6.

Deploy og innsamling har separate aktiveringskrav. T10 kan rulle ut nye web-/jobbimages og kjøre migrering/røyktest mens alle schedules er pauset. Før `enable_schedules=true` må eieren godkjenne A7-priser, pakker og kvoter, og motoroppsettet må respektere Gemini-sperren i A4. Offentlig webtilgang eller `EKKO_DEPLOY_ENABLED=true` er ikke en aktivering av innsamling.


Status 16.11.2026: offentlig webtilgang er aktivert, full smoke uten IAM består på begge opprinnelser og invitert Google-konto når dashboardet uten omlasting i vanlig Chrome. En annen uinvitert Google-konto er også avvist offentlig, med uendret antall brukere og Google-koblinger. `EKKO_DEPLOY_ENABLED=true` er satt; første automatiske main-utrulling kontrolleres etter eierens merge. Alle ni schedules er PAUSED. Se siste avsnitt i T10-statusen for aktiv revisjon og etterkontroll.

## Tilbakekall migreringslegitimasjon fra web (T39-F1)

`ekko-web` skal bare få `DB_USERNAME=ekko_app` og `DB_PASSWORD` fra `ekko-db-password`. Den skal verken få `DB_ADMIN_*` eller Secret Manager-lesetilgang til `ekko-db-admin-password`. Migreringsjobben beholder `ekko_migrate` og sitt separate passord. En endring i Terraform-filen alene tilbakekaller ikke en allerede opprettet IAM-grant.

### Kontrollert plan og apply

1. Kontroller dagens webrevisjoner, trafikk, hemmelighetsreferanser og IAM uten å lese hemmelighetsverdier. Web er nå opprettet gjennom T10. Bruk en gjennomgått konfigurasjon som kombinerer F1 med den faktisk utrullede T10-konfigurasjonen og bevarer andre ressurser. En isolert plan på main uten hele den utrullede T10-konfigurasjonen kan foreslå utilsiktede slettinger og skal ikke brukes.
2. Opprett først en webrevisjon uten `DB_ADMIN_*`. En vanlig `scripts/deploy.sh` endrer bare image og arver miljøet; den fjerner ikke disse variablene. Koordiner med øvrige deployer slik at ingen andre endrer tjenesten samtidig. Kontroller at 100 % av trafikken er låst til navngitte, verifiserte revisjoner, og noter fordelingen for tilbakeføring. Stopp hvis trafikk peker på `LATEST`. Deretter kan operatøren opprette en kandidat uten trafikk:

    ```sh
    ekko_f1_suffix="f1-$(date -u +%Y%m%d%H%M%S)"
    gcloud run services update ekko-web --project=ekko-514612 --region=europe-west1 \
      --remove-env-vars=DB_ADMIN_USERNAME --remove-secrets=DB_ADMIN_PASSWORD \
      --no-traffic --tag=f1-candidate --revision-suffix="$ekko_f1_suffix"
    ```

    Kontroller med `gcloud run services describe ekko-web --project=ekko-514612 --region=europe-west1` at kandidaten `ekko-web-$ekko_f1_suffix` er klar, at taggen `f1-candidate` peker på denne revisjonen, at kandidaten har 0 % trafikk, og at den tidligere trafikkfordelingen er uendret. Kontroller kandidatens miljø og hemmelighetsreferanser uten å skrive ut verdier: ingen `DB_ADMIN_*`, `DB_USERNAME=ekko_app`, og `DB_PASSWORD` refererer til `ekko-db-password`. Kontroller også at image og øvrig konfigurasjon er forventet.

    Bruk HTTPS-URL-en som Cloud Run viser for `f1-candidate`, og kjør den autentiserte røyktesten med den syntetiske testidentiteten som beskrevet i `docs/t10-deploy-draft.md`: `scripts/smoke.sh <kandidatens-https-url>`. Stopp ved feil; ikke tilbakekall tilgang. Når testen består, flytt trafikken til kandidaten og kontroller faktisk trafikk og offentlig røyktest:

    ```sh
    gcloud run services update-traffic ekko-web --project=ekko-514612 --region=europe-west1 \
      --to-revisions="ekko-web-$ekko_f1_suffix=100"
    ```

    Ved feil, gjenopprett den noterte trafikkfordelingen og undersøk før du fortsetter. Kontroller at ingen revisjon med trafikk refererer til migreringshemmeligheten før IAM-tilgangen tilbakekalles. En gammel revisjon med slik referanse kan ikke brukes til tilbakeføring etter tilbakekallingen uten at tilgangen først gjenopprettes kontrollert.
3. Initialiser mot `ekko-514612-tfstate`, prefix `ekko/production`. Kontroller IAM og lag en ny, lagret plan avgrenset til den eksisterende granten:

    ```sh
    terraform plan -input=false \
      '-target=google_secret_manager_secret_iam_member.read["web/db-admin-password"]' \
      -out=/tmp/ekko-web-admin-revoke.tfplan
    ```

    Dette er et avgrenset unntak for å tilbakekalle en konkret feilgrant. Hvis granten fortsatt finnes, kontroller med `terraform show` at planen viser nøyaktig **0 add, 0 change, 1 destroy**, der eneste sletting er web-medlemskapet på `ekko-db-admin-password`. Hemmeligheten, versjonene og migreringskontoens tilgang skal beholdes. Hvis planen er tom, kontroller faktisk IAM før tiltaket erklæres fullført. Ikke bruk en gammel plan eller workload-forhåndsvisningen som apply-plan.

4. Etter review/merge og kontrollene over brukes akkurat den nykontrollerte planen: `terraform apply /tmp/ekko-web-admin-revoke.tfplan`. Kontroller IAM med `gcloud secrets get-iam-policy ekko-db-admin-password --project=ekko-514612`; web skal være borte og migrate fortsatt til stede. Kontroller også at web ikke har en bredere arvet rolle som gir tilgang. Les aldri selve hemmelighetsverdien for å teste dette.
5. Kjør den samme avgrensede planen igjen med `-detailed-exitcode`; forvent returkode 0. Kontroller revisjonsmiljø og røyktest etter endringen. Kjør så en full Terraform-plan med den aktuelle T10-konfigurasjonen: gjennomgå og løs eventuell drift separat til planen er ren. Kjør ikke en full apply uten å ha kontrollert alle endringene og deres virkning på trafikk og jobber.

### Bevis 15.11.2026

Den 15.11.2026 viste live IAM både web og migrate som lesere av migreringspassordet; da fantes ingen Cloud Run-webtjeneste. En målrettet plan med datidens T10-konfigurasjon viste bare sletting av web-granten. En separat, ikke anvendt workload-plan viste riktig skille mellom web og migreringsjobben. Dette er historisk bevis, ikke en gyldig plan for dagens produksjon. Ingen passordverdier ble lest eller lagret. **Ny plan og kontroll av faktisk tilgang og revisjon kreves før funnet kan lukkes.**

## T39-F8: worker-start og SQL-tilkobling

Web kan bare starte den faste `ekko-wake`-jobben uten overrides. Veiviser og
`/internal/tick/extraction-poll` bruker denne, mens dispatch-identiteten starter
`ekko-worker`. Ved treg oppstart, sjekk først `ekko-wake`, deretter workeren.
HTTP 204 fra poll-endepunktet betyr at oppstart ble akseptert eller at en
poll-dispatcher allerede kjører. Cloud Run-jobbfeil varsles separat.

Ved samtidig poll og dagsplanlegging hopper poll over en opptatt dispatch-lås
og kontrollerer fortsatt worker-køen. Dagsplanlegging venter inntil 300 sekunder.
En overskredet ventefrist er en reell feil: undersøk den aktive dispatch-jobben
og kjør dagsplanlegging på nytt når den er ferdig. Planleggingen er idempotent.

Ved første utrulling: opprett wake-jobb og begrensede web-/CI-grants fra en
reviewet, avgrenset Terraform-plan før kode-merge. Bruk gjeldende jobs-digest.
Etter grønn deploy og verifikasjon fjernes web-grantene på workeren med en ny
avgrenset plan. Sjekk at web ikke har `run.jobs.runWithOverrides`, at dispatch
fortsatt kan starte workeren, og at wake-jobbens kommando er fast `--poll`.

Cloud SQL krever connector og TLS. Bruk Cloud SQL Auth Proxy ved kontrollerte
CLI-oppgaver; legg ikke til autoriserte offentlige IP-nettverk. Legg SQL-endringen
utenfor aktive datalastinger og ytelsesmålinger. Kontroller appens `/up`, en
ordinær deploy-røyktest og helsekontrolljobben etter endringen. Ikke tilbakefør
transportkravene for å få en direkte, ukryptert klient til å virke.

## T39-F9: workflow-tillit

Alle eksterne Actions er pinnet til commit-SHA. Ved oppdatering: slå opp taggen
hos action-eieren, gjennomgå commit/endringer og oppdater SHA og versjonskommentar
sammen. CI tester at ingen eksterne actions bruker flytende tags.

Automatisk review støtter bare PR-er fra eget repo med OWNER/MEMBER/COLLABORATOR
som forfatter. En fork-PR eller ubekreftet forfatter gir ikke adgang til betalt
review. `@claude` krever en betrodd omtale og sjekker PR-ens repo via GitHub API.
Beskrivelsesendringer skal ikke kansellere en pågående review; kode, tittel og
base krever fortsatt ny vurdering. Ved teknisk feil kan source-request kjøres
på nytt når årsaken er rettet. Ikke omgå SHA- eller reviewer-kontrollene.

WIF ble kontrollert mot live provider etter T10 den 17.11.2026: bare `push` på
`refs/heads/main` fra riktig repo-/eier-ID og `.github/workflows/ci.yml@refs/heads/main`
kan deploye. Se ADR 0031 og `infra/terraform/deploy.tf` før workflow-navn eller
repo endres. Denne oppgaven utvider ingen IAM-rettigheter.
