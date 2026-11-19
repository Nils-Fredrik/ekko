# T40 – Ende-til-ende i produksjon

Plan og logg for T40 (`docs/spec.md` kapittel 20, Board task:41). Konsernets tenants og én partner-tenant med to testkunder kjører en full uke i produksjon med lav kvote. Uka starter først når forutsetningene under er grønne og eieren har godkjent hvert produksjonssteg.

## Akseptkriterier

| Kriterium | Hvordan det måles |
|---|---|
| Alle motorer leverer | Hver aktiv motor har innsamlede svar (`runs.status = collected`) hver planlagte dag i uka, uten dager med bare feil. Gjelder motorene som er aktive i produksjon (se forutsetning 4). |
| Uttrekk og aggregering uten manuelle inngrep | Alle innsamlede svar får uttrekk (ingen `submitted` eldre enn 25 timer, `llm_failed` under 2 %). `ekko-aggregate` kjører hver time uten feil, og dashboardet viser tall for hver dag. Ingen manuelle kommandoer eller retter i databasen i løpet av uka. |
| Rapporter sendes | Ukesrapporten mandag 06:30 genereres for hver tenant og leveres til mottakerne, med kvittering per mottaker. |
| Kostnad < 15 % avvik | Faktisk kostnad per tenant for uka (`usage_events`, `cost_usd`) sammenlignes med veiviserens estimat (`POST /onboarding/cost-estimate`) omregnet til sju dager. Avvik = (faktisk − estimat) / estimat. |

## Forutsetninger (status 18.11.2026)

Lest fra produksjon uten endringer.

| # | Forutsetning | Status |
|---|---|---|
| 1 | Anthropic-nøkkel virker | Grønn. Ny nøkkel (versjon 4) er testet fra `ekko-task` med Sonnet 5.5 og strukturert utdata. |
| 2 | OpenAI- og Gemini-nøkler | Hemmelighetene har én versjon hver. Ikke testet fra produksjon i T40 ennå. |
| 3 | Priser per motor (`engine_prices`) | **Mangler.** Tabellen er tom. Uten priser kan ikke veiviseren estimere kostnad, og kostnadskriteriet kan ikke måles. Avhenger av A7. |
| 4 | Motorer | `chatgpt_api`, `claude_haiku` og `claude_sonnet` er aktive. `gemini_api` er av og sperret (`EKKO_GEMINI_GROUNDING_APPROVED=false`, A4). Perplexity og DataForSEO er sperret (`EKKO_DISABLED_ENGINE_PROVIDERS`). **Eieren må bestemme** om «alle motorer» betyr de tre aktive, eller om Gemini skal avklares først. |
| 5 | Pakker og kvoter (A7) | Pakkene Basis, Standard og Byrå finnes. Byrå har verken kallkvote eller budsjett. **Mangler:** godkjente priser, påslag og en lav kvote for første uke. |
| 6 | Anthropic-pott (12.5) | **Mangler.** `EKKO_ANTHROPIC_POOL_USD` er ikke satt, så den kredittstyrte planleggingen er av. Forbruksgrensen for Ekkos workspace i Console bør settes samtidig. |
| 7 | E-post (A6) | **Mangler.** `MAIL_MAILER` er `log` i produksjon. Rapporter, varsler og invitasjoner på e-post sendes ikke. Kriteriet «rapporter sendes» krever en e-posttjeneste og en avsenderadresse, eller at eieren godtar Slack som kanal for uka. |
| 8 | Tofaktor | **Av** (`require_two_factor=false`). Skal på før T40 (runbook, «Krav om tofaktor»). Google-bekreftelse (T07-F2) gjør at plattformadmin kan sette den opp uten passord. |
| 9 | Planlagte jobber | Alle Scheduler-jobber unntatt `ekko-monitor-health` er pauset (`enable_schedules=false`). Aktiveres først når 3–8 er avklart (runbook, «Utrulling og tilbakeføring»). |
| 10 | Tenants | `Maksimer` (Byrå, ingen merkevare ennå) finnes. `Maksimer Norge` er en testtenant fra deploy-kontrollen med kvote 0. De andre tenantene i T40 er ikke opprettet. |
| 11 | Overvåking (T36) | Grønn. Åtte varslingspolicyer er aktive, og helsekontrollen kjører hvert 15. minutt. |

## Oppsett

Etter eierens godkjenning, i denne rekkefølgen:

1. Tofaktor på (`require_two_factor = true`, apply), og kontroll av at plattformadmin uten tofaktor sendes til profilen.
2. Priser i `engine_prices` for de aktive motorene, fra Admin → Motorer (A7).
3. E-posttjeneste og avsender (A6), eller eierens beslutning om Slack for uka.
4. Anthropic-pott: `EKKO_ANTHROPIC_POOL_USD` og `cycle_start_day` i Terraform, og samme grense i Console.
5. Tenants fra Admin → Tenants, alle med lav kallkvote første uke (forslag: 2 000 kall per tenant per måned):
   - Maksimer AS (den eksisterende tenanten `Maksimer` får nytt navn), Maksimer AB, Mediebruket og Funbit
   - én partner-tenant (forslag: «Maksimer Partner») med to testkunder, «Testkunde A» og «Testkunde B», som har partneren satt
6. Én merkevare per tenant gjennom veiviseren. For Maksimer AB velges `SE/sv`. Veiviserens kostnadsestimat lagres for hver merkevare før bekreftelse, siden det er grunnlaget for kostnadskriteriet.
7. Rapportplan med ukesrapport og minst én mottaker per tenant.
8. Planlagte jobber på (`enable_schedules = true`, apply). Uka starter ved første `dispatch-due` kl. 01:00.

## Daglig sjekkliste

- Kjøringer per tenant og motor forrige døgn: `collected`, `failed` og `skipped_quota`.
- Uttrekk: ingen batch eldre enn 25 timer i `submitted`, og andel `llm_failed`.
- `ekko-aggregate`, `ekko-alerts`, `ekko-usage-rollup` og `ekko-extraction-poll`: siste kjøring er vellykket i Cloud Run.
- Dashboardet for hver merkevare viser gårsdagens tall.
- Forbruk per tenant mot kvote, og Anthropic-forbruk mot pott.
- Varslingspolicyer i Monitoring: ingen åpne hendelser.
- Avvik postes på Board (task:41) samme dag, ett funn per post.

## Avslutning

Etter sju dager:

- Tabell per tenant og motor med kjøringer, feilandel, uttrekk og kostnad mot estimat.
- Ukesrapportene og kvitteringene.
- Funn og avvik på Board.
- Lav kvote tas bort eller beholdes, etter eierens beslutning.
