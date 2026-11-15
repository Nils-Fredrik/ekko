# Driftsrunbook

## Kryssreview i GitHub (T42)

### Integrasjoner og identiteter

- Claude GitHub App skal være installert for `Nils-Fredrik/ekko`. `CLAUDE_CODE_OAUTH_TOKEN` kommer fra et eget Claude Team-sete, aldri fra Console-organisasjonens API-nøkkel. Workflowen bruker eksplisitt `claude-opus-5-5` og en commit-pinnet `anthropics/claude-code-action`.
- GitHub-repoet må være koblet til ChatGPT Codex, med kodereview aktivert. Verifiser GPT-6.1 Sol i integrasjonens tilgjengelige innstillinger. Workflowen kan utløse review med `@codex review`, men den kan ikke velge modell gjennom en dokumentert kommentarparameter. Dokumenter innstillingen før aksept; ikke anta at modellnavn i en kommentar endrer modellen.
- `CODEX_TRIGGER_TOKEN` er en separat, minst privilegert finmasket GitHub-token for å kommentere på PR-er i dette repoet. Den brukes bare av prepare-steget og eksponeres aldri for review-agenten. Kontroller at tokenets avsender faktisk kan utløse Codex; en kommentar fra `GITHUB_TOKEN` alene er ikke tilstrekkelig testbevis.
- `BOARD_MCP_TOKEN` er en dedikert CI-identitet med tilgang til dette Board-et. Den må virke som Bearer-autentisering mot `https://board.maksimer.com/mcp`. En nettleserinnlogging er ikke en CI-hemmelighet. Klienten initialiserer MCP, leser snapshot og poster bare en kort blocker med PR-referanse etter tre ulike mislykkede kode-revisjoner. Ingen diff, modelltekst eller hemmeligheter sendes til Board.

### Separat godkjenningsidentitet

Opprett en dedikert GitHub App for `agent-review`, installert bare på `Nils-Fredrik/ekko`. Den trenger Contents/Pull requests: read, Commit statuses/Issues: write. Sett offentlige `AGENT_REVIEW_APP_ID` og `AGENT_REVIEW_BOT_ID` til App-ID og dens bot-bruker-ID (ulike tall). Den delte GitHub Actions-appen/boten godtas ikke.

Opprett environment `agent-review` med **Selected branches and tags**, nøyaktig én regel av typen branch: `main`. Ikke bruk «Protected branches only» eller `refs/pull/*/merge`. Legg `AGENT_REVIEW_PRIVATE_KEY`, `CODEX_TRIGGER_TOKEN` og `BOARD_MCP_TOKEN` bare her, aldri som repo-/organisasjonshemmeligheter tilgjengelig for PR-workflows. `pull_request_target` bruker main-refen; en vanlig PR-workflow bruker pull-refen og får dermed ikke nøkkelen. Verifiser dette i en negativ test-PR. Bare prepare/gate får dette miljøet; Claude-jobben får det ikke.

Kortlivede App-tokens opprettes med den pinnede `actions/create-github-app-token` for hvert jobbtrinn og tilbakekalles av actionen. Gate-ventingen er begrenset til 45 minutter, under tokenets levetid på én time. Påkrevd `agent-review` må bindes til denne App-ID-en i grenbeskyttelsen; en ubundet status er ikke akseptabel.

### Hva sjekken kontrollerer

`agent-review.yml` kjører på `pull_request_target`, men sjekker bare ut den betrodde base-commiten. PR-kode kjøres aldri. Diff og PR-beskrivelse hentes som data; endret head, base, merge-commit, tittel eller draft-status underveis gjør sjekken rød. Filer som er flyttet fra følsomme stier regnes fortsatt som følsomme. Feil eller trunkert filliste gir aldri fritak fra review.

Claude kjører i restricted-modus og har bare Read/Glob/Grep avgrenset til arbeidsmappen og ett avgrenset MCP-verktøy for å publisere sin konklusjon på den aktuelle PR-en som Claude App. Verktøyet binder repo, PR og revisjon til workflowens kontekst; modellen kan ikke velge URL eller mottaker. Diff og kontekst ligger i `.review/` i arbeidsmappen. Lesing av `/proc`, hjemmemappen, `/tmp` og `.git` er eksplisitt avvist. Publiseringsverktøyet avviser kjente tokenmønstre og verdier på minst 16 tegn fra miljøvariabler med TOKEN, SECRET, KEY, PASSWORD eller CREDENTIAL i navnet i oppsummeringen. Ingen shell- eller filskriveverktøy er tilgjengelige. Verdict-steget validerer at strukturert konklusjon og oppsummering er identiske med kommentaren fra bot-ID 209825114, på riktig PR og full head-SHA. `track_progress` er av: i den pinnede actionen er funksjonen inkompatibel med `pull_request_target` og tag-modus kan bytte til PR-grenen. Eksplisitt prompt bruker agent-modus og beholder base-checkout.

Codex krever bot-ID 199175422, en oppdatert `codex-review-state:v1` med full head-SHA, fullført kodereview uten funn, og en ny `codex-review-receipt:v1` etter bestillingen. Kun oppdatert sticky-kommentar er utilstrekkelig: den kan også oppdateres av en sikkerhetsreview. Dette er et observert integrasjonsformat, ikke en offentlig garantert API. Formatendring, manglende kvittering eller flere statuskommentarer blokkerer. Reaksjoner eller menneskers «GODKJENT» brukes ikke som reservegodkjenning.

Statusen skrives til aktuell head-SHA av den dedikerte App-en. Head, base og test-merge-SHA beholdes i snapshotkontrollen. `main` må derfor kreve oppdatert base (`strict: true`). Tre ulike mislykkede head-SHA-er siden siste godkjenning utløser Board-blocker på T42, med lenke til den berørte PR-en. Omkjøring eller tittelredigering på samme head teller ikke som nye runder. Eieren vurderer videre retting; workflowen merger aldri. Manglende Board-autentisering gjør gaten rød, og feil ved eskalering gir ingen godkjenning. Ved feil etter vellykket Board-post men før lagret rundekvittering kan en duplikat forekomme; PR-referansen gjør den gjenkjennelig.

Den gamle automatiske `claude-code-review.yml` erstattes av denne workflowen. `claude.yml` for eksplisitt `@claude` beholdes. Slå av overlappende automatiske Codex-utløsere dersom de skaper duplikater, og verifiser den eksplisitte triggeren før aktivering.

### Kontrollert aktivering

1. Valider review-koden, CI, secrets og integrasjoner. Eieren merger T42 etter uavhengig kryssreview; `pull_request_target` kan først kjøre de nye skriptene når de finnes på base-grenen. Utkastet er ikke bevis på aktiv beskyttelse.
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

### Feil og gjenoppretting

Manglende eller utløpt token: roter den aktuelle GitHub-hemmeligheten og kjør workflowen igjen. Ikke senk godkjenningskravene for å få grønt. Ved endret Codex-format: hold sjekken rød, hent en ny anonymisert kvittering, oppdater parser og tester gjennom review. Ved endret base: oppdater PR-grenen og kjør ny review. En annullert kjøring eller formatfeil gir ingen godkjenning. Sjekk Board og PR før en ny runde etter eskalering.

Kilder kontrollert 15.11.2026: [Codex GitHub-integrasjon](https://learn.chatgpt.com/docs/third-party/github), [pinnet Claude-action og output-kontrakt](https://github.com/anthropics/claude-code-action/blob/f3b070c88125d33dbae5653df5f143101d4ea9dc/action.yml), [Claude CLI-verktøybegrensning](https://code.claude.com/docs/en/cli-reference).

Miljøreglene følger [GitHubs dokumenterte GITHUB_REF-kontroll](https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments). Repoets administrator kan fortsatt endre selve reglene; auditen og akseptbeviset må derfor lagres på Board.

`--restricted` er dokumentert i [Claude CLI-referansen](https://code.claude.com/docs/en/cli-reference) fra versjon 2.1.248. Bekreft installert CLI-versjon og MCP-miljøet i test-PR-en; ukjent flagg eller manglende miljø skal feile lukket. Prepare skriver `pending` på eventens head før API-oppslaget for mergeability, slik at en tittelendring ikke beholder gammel godkjenning ved timeout. Feil før prepare-scriptet (for eksempel utløpt App-nøkkel) krever retting og ny kjøring før eieren vurderer merge.

# Operations runbook

## Roll back web traffic

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
