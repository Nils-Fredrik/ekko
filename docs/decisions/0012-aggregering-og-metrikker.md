# 0012 – Aggregering og metrikker: dagsaggregat, MetricsService og modellbytte-markører

- **Status:** Akseptert
- **Dato:** 2026-11-15
- **Board:** `task:19` / T21

## Kontekst

`docs/spec.md` 9 definerer tolv metrikker og sier at `ekko:aggregate` bygger `agg_daily` og `agg_source_daily` på nytt for en dato, idempotent. 6.4 lister kolonnene i aggregatene, men noen av formlene (førsteplass-rate, sentiment, kildegap) kan ikke regnes ut av dem. Varsler (T24), dashboard (T26), PDF (T32), REST-API (T33) og MCP-server (T34) trenger alle de samme tallene, så formlene må ligge på ett sted. 7.5 ber om en markør når modell-ID for en motor endrer seg.

## Beslutning

Koden ligger i `App\Metrics`, jobben i `App\Jobs\AggregateTenantDays` og kommandoen i `App\Console\Commands\AggregateMetrics`.

### Aggregering

1. **`DailyAggregator::aggregate('Y-m-d')`** bygger `agg_daily`, `agg_source_daily` og modellbytte-markørene for én tenant og én dag, i tenant-konteksten som kalleren har satt. Kjøringen er idempotent: dagen regnes ut på nytt fra svar, nevnelser og sitater, rader oppdateres på plass (`upsert` på den unike nøkkelen, så `id` og `created_at` består), og rader som ikke lenger har data slettes. Den kan kjøres om igjen for alle datoer, også etter sene uttrekksresultater og etter at en nevnelse er endret.
2. **Dagen er tenantens lokale dag** (`teams.timezone`): et svar hører til den dagen `collected_at` faller på når det leses i tenantens tidssone. Vinduet er fra lokal midnatt til neste lokale midnatt (sommertid håndteres av tidssonen). Tidsstempler lagres fortsatt i UTC.
3. **Hvilke svar teller:** bare svar der kjøringen har status `extracted` (også med `llm_failed`). Et svar som venter på uttrekk drar ikke rater ned, og det kommer med ved neste kjøring. Svar fra `failed` og `skipped_quota` har ikke svar.
4. **Telling per svar** (alle tall er antall svar, ikke antall nevnelser, så de er sammenlignbare):
   - `brand_mentioned`: svaret har en `brand`-nevnelse med `entity_id` lik markedets merkevare. Nevnelser av tenantens andre merkevarer og `unknown` teller ikke.
   - `brand_recommended`: en av merkevarens nevnelser har `is_recommended`.
   - `sum_position` og `count_position`: merkevarens laveste `list_position` i svaret (ADR 0010: alltid den deterministiske). Svar uten posisjon teller ikke her.
   - `sum_sentiment` og `count_sentiment`: sum og antall av merkevarens nevnelser med sentiment (tre desimaler, summert som tusendeler, så summen er eksakt).
   - `own_cited`: svaret har minst ett sitat med `ownership = own`. I en tenant med flere merkevarer er `own` alle tenantens merkevaredomener (ADR 0010 punkt 9), så `own_cited` kan telle et sitat til en annen av tenantens merkevarer.
   - `competitor_mentions`: `{competitor_id: antall svar som nevner konkurrenten}`, bare for konkurrenter på samme merkevare. Alle konkurrenter lagres, også inaktive. «Aktive» avgjøres når tallene leses.
5. **Nye kolonner** (avvik fra 6.4, ført inn i spesifikasjonen): `agg_daily.list_responses` (listesvar: `answer_type = list`, eller et svar der en kjent entitet har listeposisjon, så svar med `llm_failed` teller også), `first_place` (listesvar der merkevaren er nummer 1), `count_sentiment` og `competitor_only_responses` (svar som nevner en konkurrent men ikke merkevaren), og `agg_source_daily.gap_responses` (svar som siterer domenet, nevner en konkurrent og ikke merkevaren, der domenet ikke er merkevarens eget).
6. **Kommandoen** `ekko:aggregate [dato] [--from= --to=] [--tenant=*] [--sync]` legger én `AggregateTenantDays`-jobb per tenant i køen (`--sync` kjører dem i prosessen). Jobben utvider `TenantJob`, så den kjører med tenant-kontekst og RLS. Uten dato er dagene «i går» og «i dag» i hver tenants egen tidssone, slik at sene resultater for i går kommer med. Tenanter med status `closed` og personlige team hoppes over. Kommandoen er planlagt hver time (`routes/console.php`). Områder over 400 dager avvises.

### MetricsService

`App\Metrics\MetricsService` er det eneste stedet formlene finnes (`App\Metrics\Formulas` er de rene formlene, `MetricsService` summerer tellerne i databasen). Alle kall leser tenanten i konteksten, og ukes- og månedstall er summer over dagene i spørringen, aldri lagret (6.4). Brukes slik av T24, T26, T32, T33 og T34:

```php
$filter = new MetricsFilter(brandId: 7, from: '2026-11-01', to: '2026-11-30',
    marketIds: [], engineKeys: [], topicIds: [], intents: [], funnelStages: [], promptIds: []);

$service = app(MetricsService::class);
$service->summary($filter);                 // MetricsSummary: alle hovedmetrikker, hver som Metric(value, n)
$service->visibility($filter);              // også recommendationRate, shareOfVoice, averagePosition,
                                            // firstPlaceRate, citationRate, sentiment: Metric
$service->series($filter, 'week');          // day|week|month: [['period' => 'Y-m-d', 'summary' => MetricsSummary]]
$service->breakdown($filter, 'engine');     // market|engine|prompt|topic|intent|funnel_stage => [verdi => MetricsSummary]
$service->stability($filter);               // ['mean' => Metric, 'distribution' => ['0.50' => antall, …]]
$service->sourceShare($filter, 50);         // Kildeandel per domene
$service->sourceGap($filter, 50);           // Kildegap per domene
$service->botHits($filter);                 // AI-robottreff per bot
$service->aiTraffic($filter);               // AI-trafikk per kilde
$service->modelChanges('2026-11-01', '2026-11-30', ['chatgpt_api']);  // Collection<ModelChange>
```

Regler:

- `Metric` har `value` (andel fra 0 til 1, ikke prosent; `null` når det ikke finnes noe å regne på) og `n`. `isLowSample()` er `n < 10` (spec 9: nedtonet). `toArray()` gir `{value, n, low_sample}` for API og MCP.
- **n per metrikk:** svar for synlighet, anbefalingsrate, share of voice og sitatrate. Svar med listeposisjon for gjennomsnittlig posisjon. Listesvar for førsteplass-rate. Nevnelser med sentiment for sentiment. Celler for stabilitet.
- **Share of voice** = merkevarens svar med nevnelse ÷ (merkevarens + summen for merkevarens aktive konkurrenter). Inaktive konkurrenter og andre merkevarers konkurrenter er ikke med. Gjøres en konkurrent inaktiv, endrer historiske tall seg.
- **Førsteplass-rate** = `first_place` ÷ `list_responses` (alle listesvar, også de der merkevaren ikke står).
- **Stabilitet** regnes per prompt, motor og dag (en runde). Bare celler med minst to svar teller, siden én gjentakelse ikke gir noe å sammenligne. Bruker `agg_daily.brand_mentioned ÷ responses` per celle. To kjøringer samme dag for samme prompt og motor slås sammen til én celle. Fordelingen avrundes til to desimaler.
- **Kildeandel** = `responses_with_domain` ÷ merkevarens svar i samme markeder, motorer og periode. **Kildegap** = `gap_responses` ÷ `competitor_only_responses` (svar som nevner konkurrenter men ikke merkevaren), sortert på antall. `agg_source_daily` har ikke prompt-dimensjonen, så kildefigurene avviser filter på tema, intensjon, trinn og prompt (`InvalidArgumentException`).
- Ukedager følger ISO (mandag først). Første og siste periode i en serie er delvise når filteret ikke starter eller slutter på en grense.

### Modellbytte-markører

Tabellen `model_changes` (`tenant_id`, `engine_key`, `changed_on`, `from_model`, `to_model`, `changed_at`, unik på tenant, motor og dag) er tenant-eid med RLS og policy (alle roller leser). `DailyAggregator` skriver én markør per motor og dag: første gang den dagen motoren svarer med en annen `model_id_reported` enn forrige svar med modell-ID (også forrige dags siste). Svar uten modell-ID ignoreres, og første modell som noen gang sees er ikke et bytte. Kjøres dagen om igjen, regnes markøren ut på nytt. Markøren for en dag avhenger av dagene før, så et sent svar på dag N endrer ikke markøren på dag N+1 før den dagen kjøres om (den planlagte kjøringen tar i går og i dag). Dashboardet (T26) leser dem med `MetricsService::modelChanges()`.

### Indekser

Migrasjonen legger til `agg_daily(tenant_id, brand_id, market_id, engine_key, date)` og `agg_daily(tenant_id, brand_id, prompt_id, date)`, og `agg_source_daily(tenant_id, brand_id, market_id, engine_key, date)` og `agg_source_daily(tenant_id, brand_id, domain, date)`, ved siden av `(tenant_id, brand_id, date)` som fantes. De dekker dashboardets filtre (merkevare, marked, motor, prompt, periode). Ytelsestesten (T37) måler mot dem.

## Konsekvenser

- Alle som viser eller varsler på metrikker kaller `MetricsService`, ikke `agg_daily` direkte. Nye formler legges i `Formulas` og `MetricsService`.
- T17 (dispatcher) og T24 (varsler) bør kjøre `ekko:aggregate` (eller `AggregateTenantDays`) før de leser tall for en dag. Varsler bør vente til uttrekk er ferdig for dagen, siden bare `extracted`-svar teller.
- Endrer uttrekk eller gjenkjenning seg tilbake i tid, kjøres `ekko:aggregate --from= --to=` for perioden.
- Stabilitet er en tilnærming med `agg_daily` som kilde (runde = dag). Trengs eksakt per runde (`run_batches`), må `agg_daily` utvides eller beregningen flyttes til råtabellene.
- `own_cited` følger `ownership` slik det er lagret og skiller ikke mellom flere merkevarer i samme tenant.
