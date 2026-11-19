# 0034 – Tiltaksmotor: kilder, gjenkjenning, utløp og formulering

- **Status:** Foreslått
- **Dato:** 2026-11-19
- **Board:** `task:76` / T43. Bygger på [ADR 0012](0012-aggregering-og-metrikker.md) (metrikker) og [ADR 0023](0023-anthropic-kredittplan.md) (Anthropic-kreditter).

## Kontekst

Spesifikasjonen 11.3 beskriver hva et tiltak er, de fem typene, rangeringen, statusene og at tallene i evidensen aldri skrives av modellen. Den sier ikke hvordan samme funn gjenkjennes, når et funn regnes som «ikke støttet», hva som skjer med tiltak brukeren jobber med, hvordan tallforbudet håndheves, eller hvordan Sonnet-kallene budsjetteres. T44 (grensesnitt), T45 (markører), T47 (pitch), T48 (faktasjekk) og T50 (API og MCP) bygger på datamodellen.

## Beslutning

**Kilder.** Hver tiltakstype er en `ActionSource` i `ekko.actions.sources`. En kilde får merkevaren, de aktive markedene og dagens lokale dato, og returnerer kandidater og hvilke omfang (marked, eller `null` for merkevarenivå) den kunne vurdere. Kildene leser tall via `MetricsService`, som får `reach`, `sourceGapDetail` og `unknownBrandDetail`. Et marked med færre enn `ekko.actions.min_answers` svar i vinduet vurderes ikke. `fact_correction` er ikke registrert ennå: T48 legger inn sin kilde og bestemmer evidens og `text_params` (malen bruker `:fact`, `:prompts` og `:source`).

**Gjenkjenning.** `fingerprint` er sha256 av type, merkevare, marked (eller `-`) og funnets nøkkel med små bokstaver: domene, `topic:<id>`, navn eller robot. Unik per tenant der fingerprint finnes; egne tiltak har ingen. Ved treff:
- `new`, `planned`, `in_progress`: ny evidens, effekt, motorer og prompts; malteksten fornyes.
- `expired`: åpnes igjen som `new`.
- `done`, `declined`: står urørt, også evidensen fra da brukeren avsluttet.

**Utløp.** En generering teller per merkevare og lokal ISO-uke (`action_generations`, unik). For hver kilde: åpne systemtiltak av kildens type i et vurdert omfang som ikke ble funnet, får `unsupported_generations + 1`; ved 2 settes `expired`. Bare `new` og `planned` utløper. `in_progress` utløper ikke: arbeidet kan være grunnen til at funnet forsvant, og markøren skal stå. Omfang kilden ikke kunne vurdere (for lite data, ingen robotdata), teller ikke. `--force` oppdaterer samme uke uten å telle.

**Effekt.** `impact_score` = berørte svar per måned × andel av dem der konkurrenter nevnes og merkevaren ikke gjør det. Per type:
- `source_gap`: svar som siterer domenet × andel kildegap.
- `owned_content`: svar på de svake promptene × andel bare-konkurrent.
- `new_competitor`: svar som nevner navnet × andel uten merkevaren.
- `crawler_access`: alle merkevarens svar × andel bare-konkurrent.
Nivå 1–5 fra tersklene i `ekko.actions.impact_levels` (2, 5, 15, 40 per måned). Rangering (`Action::ranked()`), per merkevare: `fact_correction` først, så nivå, så score, så eldste.

**Tall og tekst.** Evidensen lagres som JSON med periode og tallene, pluss `text_params` til maltekster og evidenssetning (`lang/*/action_items.php`). Modellen får evidensen, men skal skrive uten tall. `ActionTextGuard` fjerner først navnene modellen fikk (domene, merkevare, tema, konkurrenter, prompttekster) fra teksten, lengste først, og avviser teksten hvis det fortsatt står et tall igjen. Et tall er altså bare lov som del av et navn skrevet fullt ut; et årstall i en prompt slipper bare gjennom når hele prompten siteres. For lang eller feil formet tekst avvises også. Da står malteksten. Slik blir modelltekst aldri utdatert når evidensen fornyes, og tallet modellen skriver kan ikke være oppdiktet.

**Formulering og budsjett.** Nye tiltak får maltekst med en gang, på nb, sv eller en etter markedets språk (`locale` er språket teksten står på). Modellen skriver på markedets egentlig språk, også da, fi eller de, og `locale` settes da til markedets språktag. Etter generering formuleres de ett og ett utenfor transaksjon, høyst `ekko.actions.max_formulations` per tenant. Kallet går med SDK-en uten automatisk retry, strukturert JSON-output og `effort: low`. Før kallet reserveres estimert kost på Anthropic-kontoen uten å være tillatt i redusert modus (nivå 2-lignende tillegg). Kjent svar gjøres opp med `settleKnown` og bokføres i `usage_events` (`kind = actions`, `billed_to = platform`) også når teksten avvises. Kjent avslag frigjør reservasjonen; ukjent utfall beholder den (ADR 0023). «Credit balance is too low» setter kredittstopp. Tiltak uten modelltekst prøves igjen ved neste generering så lenge de er `new`. Brukerens endring før svaret kommer vinner.

**Status.** `ActionWorkflow` er eneste vei for brukerendringer. Brukeren kan ikke sette `new` eller `expired`. `started_at` settes første gang tiltaket går til `in_progress`; `completed_at` settes ved `done` og nullstilles ved gjenåpning. Begge lagres som UTC-tidspunkt; T45 regner om til tenantens lokale dato. Endringer logges i revisjonsloggen uten tittelen. Policy: alle roller leser, redaktør og admin endrer (`ManagePrompts`).

## Konsekvenser

- T44 og T50 bruker `ActionWorkflow` og `ActionPolicy`, og viser `ActionTemplates::evidenceSentence()` ved siden av modellteksten. Rapporten og pitch (T47) bruker `Action::query()->open()->ranked()`.
- T48 registrerer en `fact_correction`-kilde; rangeringen øverst følger av typen.
- `usage_events.kind` har en ny verdi, `actions`. Et formuleringskall teller som ett kall i kallkvoten.
- Terskler og vinduer kan endres i konfigurasjon uten kodeendring. Planen `actions` i Cloud Scheduler er pauset til `enable_schedules` slås på.
