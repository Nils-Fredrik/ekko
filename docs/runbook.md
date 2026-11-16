# Driftsrunbook

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

`agent-review.yml` («Agent review request») kjører på `pull_request_target`, sjekker bare ut betrodd base og setter status til pending. `agent-review-execute.yml` starter deretter på `workflow_run` og bruker bare sin betrodde main-commit (`github.sha`). `source.mjs` verifiserer kildekjøringens repo, workflow-ID/sti, event, vellykket resultat og forsøksnummer via GitHub API. Det må finnes nøyaktig én PR med samme head og base som den betrodde checkout-en. Et workflow-navn alene er ikke tilstrekkelig: PR-kode kan lage en workflow med samme navn. Ingen artifacts fra kildekjøringen lastes ned. PR-kode kjøres aldri. Diff og PR-beskrivelse hentes som data; endret head, base, merge-commit, tittel eller draft-status underveis gjør sjekken rød. Filer som er flyttet fra følsomme stier regnes fortsatt som følsomme. Feil eller trunkert filliste gir aldri fritak fra review.

Claude kjører i restricted-modus og har bare Read/Glob/Grep avgrenset til arbeidsmappen og ett avgrenset MCP-verktøy for å publisere sin konklusjon på den aktuelle PR-en som Claude App. Verktøyet binder repo, PR og revisjon til workflowens kontekst; modellen kan ikke velge URL eller mottaker. Diff og kontekst ligger i `.review/` i arbeidsmappen. `--restricted` sperrer filer utenfor arbeidsmappen; `/proc`, `/tmp` og `.git` er i tillegg eksplisitt avvist. Ikke bruk `Read(~/**)` som deny: GitHub-runnerens repo ligger også under hjemmemappen. Tillat verktøynavnene Read/Glob/Grep; Glob(path)/Grep(path) avgrenser ikke filer, mens restricted håndhever arbeidsmappen. Publiseringsverktøyet avviser kjente tokenmønstre og verdier på minst 16 tegn fra miljøvariabler med TOKEN, SECRET, KEY, PASSWORD eller CREDENTIAL i navnet i oppsummeringen. Ingen shell- eller filskriveverktøy er tilgjengelige. Publiseringsverktøyet returnerer hele objektet med conclusion, summary og comment_id, som revieweren skal kopiere uendret til sitt strukturerte resultat. Verdict-steget validerer fortsatt at strukturert konklusjon og oppsummering er identiske med kommentaren fra bot-ID 209825114, på riktig PR og full head-SHA. `track_progress` er av. Eksplisitt prompt bruker agent-modus på workflow_run og beholder main-checkout. Claude får fortsatt sitt eget App-token fra Anthropic; vi erstatter det ikke med den delte GitHub Actions-identiteten.

Codex krever bot-ID 199175422, en oppdatert `codex-review-state:v1` med full head-SHA, fullført kodereview uten funn, og en ny `codex-review-receipt:v1` etter bestillingen. Kun oppdatert sticky-kommentar er utilstrekkelig: den kan også oppdateres av en sikkerhetsreview. Dette er et observert integrasjonsformat, ikke en offentlig garantert API. Formatendring, manglende kvittering eller flere statuskommentarer blokkerer. Reaksjoner eller menneskers «GODKJENT» brukes ikke som reservegodkjenning.

Statusen skrives til aktuell head-SHA av den dedikerte App-en. Head, base og test-merge-SHA beholdes i snapshotkontrollen. `main` må derfor kreve oppdatert base (`strict: true`). Tre ulike mislykkede head-SHA-er siden siste godkjenning utløser Board-blocker på T42, med lenke til den berørte PR-en. Omkjøring eller tittelredigering på samme head teller ikke som nye runder. Eieren vurderer videre retting; workflowen merger aldri. Manglende Board-autentisering gjør gaten rød, og feil ved eskalering gir ingen godkjenning. Ved feil etter vellykket Board-post men før lagret rundekvittering kan en duplikat forekomme; PR-referansen gjør den gjenkjennelig.

Den gamle automatiske `claude-code-review.yml` erstattes av denne workflowen. `claude.yml` for eksplisitt `@claude` beholdes. Slå av overlappende automatiske Codex-utløsere dersom de skaper duplikater, og verifiser den eksplisitte triggeren før aktivering.

### Kontrollert aktivering

1. Valider review-koden, CI, secrets og integrasjoner. Eieren merger T42 etter uavhengig kryssreview; Begge workflows må finnes på main før ende-til-ende-testen kan kjøre. En PR som retter selve oppstartsfeilen vurderes med eksplisitt uavhengig review og grønn test-CI før eierens merge; en rød automatisk oppstartssjekk er ikke et godkjent review. Utkastet er ikke bevis på aktiv beskyttelse.
2. Opprett tre små test-PR-er: en ufølsom `[Codex]`, en ufølsom `[Claude Code]`, og en følsom endring. Kontroller henholdsvis Claude, Codex og begge. Gi alle beskrivelse med Board-oppgave og akseptkriterier. Bruk ingen kundedata. Ta med en push fra en av de tillatte botene `claude`, `chatgpt-codex-connector` eller `cursor`; kontroller at review-jobben faktisk starter. Lukk test-PR-ene uten merge.
3. Kontroller synlig Claude-botkommentar, strukturert konklusjon, fersk Codex-kvittering, korrekt head/merge-SHA og grønn `agent-review`. Test en ny commit og en endret base: gammel godkjenning må ikke godta ny kode. Test tre mislykkede runder og kontroller én Board-blocker.
4. Først når testene fungerer, aktiver grenbeskyttelse på `main`: krev `test` og `agent-review` (bundet til `AGENT_REVIEW_APP_ID`), oppdatert base, håndheving også for administratorer, og ingen force-push eller sletting. Behold eventuelle strengere eksisterende regler. Ikke sett krav om menneskelig egen-PR-godkjenning som eieren ikke kan oppfylle; agent-godkjenning håndheves av `agent-review`.
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

6. Dokumenter test-PR-er, revisjoner, CI og beskyttelseskontroll på Board. T42 lukkes først når hele aksepten er bekreftet. Eieren er fortsatt den eneste som merger.

### Akseptbevis fra 15.11.2026

Aktivert 15.11.2026: `main` krever `strict: true`, `test` fra GitHub Actions (App-ID `15368`) og `agent-review` fra Ekko Agent Review NF (App-ID `5773706`, bot-ID `357077641`). Kravene gjelder også administratorer. Force-push og sletting er av. `check-protection.mjs` består mot metadata hentet etter aktivering, inkludert miljøets eneste tillatte branch `main`.

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

### Feil og gjenoppretting

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
