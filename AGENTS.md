# Ekko – instruksjoner for agentene

Ekko overvåker merkevarer, nøkkelord og kilder i AI-søk. All funksjonell og teknisk beskrivelse står i `docs/spec.md`. Les den før du starter en oppgave, og følg den. Avvik registreres på Board og oppdateres i `docs/spec.md` i samme PR.

## Hvem er du

| Agent | Merkelapp |
|---|---|
| Claude Code | `[Claude Code]` (står også i `CLAUDE.md`) |
| Cursor | `[Cursor]` (står også i `.cursor/rules/ekko.mdc`) |
| Codex | `[Codex]` |

Du tar bare oppgaver med din egen merkelapp.

## Board

- Board: https://board.maksimer.com/b/vn4w66vc (board-ID `vn4w66vc`), via Board-MCP.
- Oppgavetittelen har formen «[Agent] T<nr> – <tittel>». T-nummeret er spesifikasjonens arbeidspakke (`docs/spec.md`, kapittel 20). Board-oppgavenummeret (`task:<n>`) er et annet tall. Bruk Board-nummeret i Board-kallene og T-nummeret i grener og PR-er.
- Avhengighetene er lagt inn på Board. En oppgave er klar når alle oppgavene den avhenger av er lukket.

## Arbeidsflyt for hver økt

1. Les hendelser rettet til deg på Board (`for_me`).
2. Sjekk egne åpne PR-er i `Nils-Fredrik/ekko`. Har en reviewer bedt om endringer, retter du dem først, i samme gren.
3. List oppgavene på Board. Velg den første åpne oppgaven med din merkelapp der alle avhengigheter er lukket. Finnes ingen, si fra og stopp.
4. Reserver oppgaven (`claim`) og start den (`start`).
5. Lag gren fra oppdatert `origin/main`:
   ```
   git fetch origin
   git switch -c t<nr>-<kort-beskrivelse> origin/main
   ```
6. Gjør oppgaven etter beskrivelsen og akseptkriteriene på Board og i `docs/spec.md`.
7. Post på Board underveis, ett poeng per post: `decision`, `blocker`, `finding` eller `question`. Maks 500 tegn, ingen hemmeligheter eller kundedata.
8. Kjør tester og kodekontroller lokalt (`composer test`, `composer lint`, `npm run lint`) før du åpner PR. CI (`.github/workflows/ci.yml`) kjører det samme mot PostgreSQL 16 på hver PR og må være grønn før merge.
9. Åpne PR med tittel «[Agent] T<nr> – <tittel>» (samme som Board-oppgaven) og en beskrivelse med:
   - Board-oppgave (`task:<n>`) og T-nummer
   - hva som er gjort
   - hvilke akseptkriterier som er oppfylt
   - testbevis (kommandoer og resultat)
   - eventuelle avvik fra spesifikasjonen
10. Legg PR-en på Board-oppgaven som `pr:Nils-Fredrik/ekko#<nr>` og post `status` med testbevis.
11. Eieren merger etter kryssreview. Oppgaven lukkes når PR-en er merget.

## Regler

Reglene under er de felles reglene i `docs/spec.md` 19.2.

- **Kilde for krav:** `docs/spec.md`. `CLAUDE.md` importerer denne filen, Codex leser den direkte, og Cursor har en regel i `.cursor/rules/ekko.mdc` som peker hit og til spesifikasjonen.
- **Én oppgave = én gren = én PR.** Grennavn `t<nr>-<kort-beskrivelse>`, f.eks. `t12-chatgpt-api-adapter`. PR-tittelen starter med merkelappen og T-nummeret, f.eks. «[Codex] T12 – Adapter chatgpt_api». Review-workflowen bruker merkelappen til å velge reviewer.
- **Åpne review-kommentarer først:** har en reviewer bedt om endringer i en av dine PR-er, retter du dem før du tar en ny oppgave.
- **Merge** gjøres av Nils-Fredrik (eieren), aldri av en agent.
- **Tilgang:** agentenes GitHub-integrasjoner har skrivetilgang til `ekko` og lesetilgang til `varde`.
- **Kryssreview** etter `docs/spec.md` 19.5. Ingen merge uten godkjent review fra en annen agent og grønn CI.
- **Tester:** ikke deaktiver, hopp over eller svekk tester for å få grønt bygg. Isolasjonstestene for tenants er alltid blokkerende.
- **Nye ruter og MCP-tools** føres inn i registeret i `tests/Feature/TenantIsolation/routes.php` (eller `mcp-tools.php`) med tilgangsklasse, og en rute med parameter må si hvordan en annen tenants ressurs testes. Uten oppføring feiler `composer test`. Tenant-eide modeller bruker `BelongsToTenant`, og køjobber arver `App\Jobs\TenantJob` (`docs/decisions/0005-isolasjonstestsuite.md`).
- **Varde** (`Nils-Fredrik/varde`) er kun referanse. Les og kopier mønstre derfra (`docs/spec.md` 4.1), men gjør aldri endringer, commits, grener eller PR-er i Varde. Kopier aldri miljøfiler eller hemmeligheter.
- **Ingen hemmeligheter, kundedata eller råsvar** i commits, PR-er eller Board-poster.
- **Språk:** kode, navn i kode og commit-meldinger på engelsk. PR-beskrivelser og Board-poster på norsk. Alle brukertekster i språkfiler (`nb`, `sv`, `en`), aldri hardkodet.
- **Arkitekturbeslutninger** som ikke står i spesifikasjonen, dokumenteres som ADR i `docs/decisions/`.
- **Claude Code** brukes med innlogging på eget Team-sete, aldri med API-nøkkel fra Console-organisasjonen som mottar Team-kredittene.

## Lokalt oppsett

Hver agent jobber i sin egen `git worktree`, aldri i samme mappe som en annen agent.

| Agent | Mappe | Database | App-port | Vite-port |
|---|---|---|---|---|
| Claude Code | `~/kode/ekko-claude` | `ekko_claude` | 8001 | 5174 |
| Cursor | `~/kode/ekko-cursor` | `ekko_cursor` | 8002 | 5175 |
| Codex | `~/kode/ekko-codex` | `ekko_codex` | 8003 | 5176 |

Testene bruker en egen database per worktree, `<database>_testing` (f.eks. `ekko_claude_testing`), satt med `DB_TEST_DATABASE`. Testene treffer aldri utviklingsdatabasen (`docs/decisions/0001-app-i-rotmappen-og-testdatabase.md`).

Hver worktree har også sin egen sesjonscookie, `<database>_session` (f.eks. `ekko_claude_session`), satt med `SESSION_COOKIE`. Nettleseren skiller ikke cookies på port, så uten dette logger én worktree ut de andre når de åpnes i samme nettleser.

Oppsett med ett skript, som lager databasene, skriver `.env` (bare hvis den mangler), installerer avhengigheter, migrerer og bygger frontend:

```
scripts/agent-setup.sh claude   # eller cursor, codex
```

Krever PostgreSQL 16 lokalt. Har databasen en annen bruker enn `postgres`, sett `PGUSER` (og eventuelt `PGHOST`) før skriptet kjøres. Vil du sette opp manuelt: kopier `.env.example` til `.env` og sett `DB_DATABASE`, `DB_TEST_DATABASE`, `DB_USERNAME`, `SESSION_COOKIE`, `APP_PORT`/`APP_URL` og `VITE_PORT` etter tabellen. Har du en `.env` fra før, legger skriptet til `SESSION_COOKIE` hvis den mangler, og endrer ikke noe annet. `.env` committes aldri.

Skybaserte økter (Codex i skyen, Cursors bakgrunnsagenter) kjører `scripts/agent-setup.sh cloud`, som også installerer og starter Postgres.

### Kommandoer

```
composer dev        # app, kø, logg og Vite (porter fra APP_PORT og VITE_PORT i .env)
composer test       # Pest mot <database>_testing
composer test:isolation  # bare isolasjonstestene (tenant, tilgang, ruter)
composer lint       # Pint (--test) og Larastan nivå 6
composer format     # Pint
npm run lint        # ESLint og Prettier (--check)
npm run format      # Prettier og ESLint --fix
npm run build
```
