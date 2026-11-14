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
8. Kjør tester og kodekontroller lokalt (`composer test`, `npm run lint`) før du åpner PR.
9. Åpne PR med tittel «[Agent] T<nr> – <tittel>» (samme som Board-oppgaven) og en beskrivelse med:
   - Board-oppgave (`task:<n>`) og T-nummer
   - hva som er gjort
   - hvilke akseptkriterier som er oppfylt
   - testbevis (kommandoer og resultat)
   - eventuelle avvik fra spesifikasjonen
10. Legg PR-en på Board-oppgaven som `pr:Nils-Fredrik/ekko#<nr>` og post `status` med testbevis.
11. Eieren merger etter kryssreview. Oppgaven lukkes når PR-en er merget.

## Regler

- **Én oppgave = én gren = én PR.**
- **Kryssreview** etter `docs/spec.md` 19.5. Ingen merge uten godkjent review fra en annen agent og grønn CI.
- **Tester:** ikke deaktiver, hopp over eller svekk tester for å få grønt bygg. Isolasjonstestene for tenants er alltid blokkerende.
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

Kopier `.env.example` til `.env` i din mappe og sett `DB_DATABASE`, `APP_PORT`/`APP_URL` og `VITE_PORT` etter tabellen. `.env` committes aldri.

Skybaserte økter (Codex i skyen, Cursors bakgrunnsagenter) bruker `scripts/agent-setup.sh` (lages i T01).
