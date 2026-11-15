# Driftsrunbook

## Kryssreview i GitHub (T42)

### Integrasjoner og identiteter

- Claude GitHub App skal være installert for `Nils-Fredrik/ekko`. `CLAUDE_CODE_OAUTH_TOKEN` kommer fra et eget Claude Team-sete, aldri fra Console-organisasjonens API-nøkkel. Workflowen bruker eksplisitt `claude-opus-5-5` og en commit-pinnet `anthropics/claude-code-action`.
- GitHub-repoet må være koblet til ChatGPT Codex, med kodereview aktivert. Verifiser GPT-6.1 Sol i integrasjonens tilgjengelige innstillinger. Workflowen kan utløse review med `@codex review`, men den kan ikke velge modell gjennom en dokumentert kommentarparameter. Dokumenter innstillingen før aksept; ikke anta at modellnavn i en kommentar endrer modellen.
- `CODEX_TRIGGER_TOKEN` er en separat, minst privilegert finmasket GitHub-token for å kommentere på PR-er i dette repoet. Den brukes bare av prepare-steget og eksponeres aldri for review-agenten. Kontroller at tokenets avsender faktisk kan utløse Codex; en kommentar fra `GITHUB_TOKEN` alene er ikke tilstrekkelig testbevis.
- `BOARD_MCP_TOKEN` er en dedikert CI-identitet med tilgang til dette Board-et. Den må virke som Bearer-autentisering mot `https://board.maksimer.com/mcp`. En nettleserinnlogging er ikke en CI-hemmelighet. Klienten initialiserer MCP, leser snapshot og poster bare en kort blocker med PR-referanse etter tre ulike mislykkede kode-revisjoner. Ingen diff, modelltekst eller hemmeligheter sendes til Board.

### Hva sjekken kontrollerer

`agent-review.yml` kjører på `pull_request_target`, men sjekker bare ut den betrodde base-commiten. PR-kode kjøres aldri. Diff og PR-beskrivelse hentes som data; endret head, base, merge-commit, tittel eller draft-status underveis gjør sjekken rød. Filer som er flyttet fra følsomme stier regnes fortsatt som følsomme. Feil eller trunkert filliste gir aldri fritak fra review.

Claude har bare Read/Glob/Grep og ett avgrenset MCP-verktøy for å publisere sin konklusjon på den aktuelle PR-en som Claude App. Verktøyet binder repo, PR og revisjon til workflowens kontekst; modellen kan ikke velge URL eller mottaker. Ingen shell- eller filskriveverktøy er tilgjengelige. Verdict-steget validerer at strukturert konklusjon og oppsummering er identiske med kommentaren fra bot-ID 209825114, på riktig PR og full head-SHA. `track_progress` er av: i den pinnede actionen er funksjonen inkompatibel med `pull_request_target` og tag-modus kan bytte til PR-grenen. Eksplisitt prompt bruker agent-modus og beholder base-checkout.

Codex krever bot-ID 199175422, en oppdatert `codex-review-state:v1` med full head-SHA, fullført kodereview uten funn, og en ny `codex-review-receipt:v1` etter bestillingen. Kun oppdatert sticky-kommentar er utilstrekkelig: den kan også oppdateres av en sikkerhetsreview. Dette er et observert integrasjonsformat, ikke en offentlig garantert API. Formatendring, manglende kvittering eller flere statuskommentarer blokkerer. Reaksjoner eller menneskers «GODKJENT» brukes ikke som reservegodkjenning.

Statusen skrives til den aktuelle test-merge-commiten. `main` må derfor kreve oppdatert base (`strict: true`). Tre ulike mislykkede head-SHA-er siden siste godkjenning utløser Board-blocker på T42, med lenke til den berørte PR-en. Omkjøring eller tittelredigering på samme head teller ikke som nye runder. Eieren vurderer videre retting; workflowen merger aldri. Manglende Board-autentisering gjør at oppstart feiler, og feil ved eskalering gir rød sjekk.

Den gamle automatiske `claude-code-review.yml` erstattes av denne workflowen. `claude.yml` for eksplisitt `@claude` beholdes. Slå av overlappende automatiske Codex-utløsere dersom de skaper duplikater, og verifiser den eksplisitte triggeren før aktivering.

### Kontrollert aktivering

1. Valider review-koden, CI, secrets og integrasjoner. Eieren merger T42 etter uavhengig kryssreview; `pull_request_target` kan først kjøre de nye skriptene når de finnes på base-grenen. Utkastet er ikke bevis på aktiv beskyttelse.
2. Opprett tre små test-PR-er: en ufølsom `[Codex]`, en ufølsom `[Claude Code]`, og en følsom endring. Kontroller henholdsvis Claude, Codex og begge. Gi alle beskrivelse med Board-oppgave og akseptkriterier. Bruk ingen kundedata. Lukk test-PR-ene uten merge.
3. Kontroller synlig Claude-botkommentar, strukturert konklusjon, fersk Codex-kvittering, korrekt head/merge-SHA og grønn `agent-review`. Test en ny commit og en endret base: gammel godkjenning må ikke godta ny kode. Test tre mislykkede runder og kontroller én Board-blocker.
4. Først når testene fungerer, aktiver grenbeskyttelse på `main`: krev `test` og `agent-review`, oppdatert base, håndheving også for administratorer, og ingen force-push eller sletting. Behold eventuelle strengere eksisterende regler. Ikke sett krav om menneskelig egen-PR-godkjenning som eieren ikke kan oppfylle; agent-godkjenning håndheves av `agent-review`.
5. Kontroller uten å endre noe:

```sh
gh api repos/Nils-Fredrik/ekko/branches/main/protection \
  | node scripts/agent-review/check-protection.mjs
```

6. Dokumenter test-PR-er, revisjoner, CI og beskyttelseskontroll på Board. T42 lukkes først når hele aksepten er bekreftet. Eieren er fortsatt den eneste som merger.

### Feil og gjenoppretting

Manglende eller utløpt token: roter den aktuelle GitHub-hemmeligheten og kjør workflowen igjen. Ikke senk godkjenningskravene for å få grønt. Ved endret Codex-format: hold sjekken rød, hent en ny anonymisert kvittering, oppdater parser og tester gjennom review. Ved endret base: oppdater PR-grenen og kjør ny review. En annullert kjøring eller formatfeil gir ingen godkjenning. Sjekk Board og PR før en ny runde etter eskalering.

Kilder kontrollert 15.11.2026: [Codex GitHub-integrasjon](https://learn.chatgpt.com/docs/third-party/github), [pinnet Claude-action og output-kontrakt](https://github.com/anthropics/claude-code-action/blob/f3b070c88125d33dbae5653df5f143101d4ea9dc/action.yml), [Claude CLI-verktøybegrensning](https://code.claude.com/docs/en/cli-reference).
