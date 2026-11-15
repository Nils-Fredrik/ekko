# 0017 – Varsler: evaluering, døgnmelding, levering og kvittering

- **Status:** Foreslått
- **Dato:** 2026-11-15
- **Board:** `task:22` / T24

## Kontekst

`docs/spec.md` 11.1 lister sju varseltyper, tre kanaler og krever én melding per tenant per døgn. Modellene `Alert` og `AlertRule` fantes fra T08. Kvoterollupen (T19, ADR 0013) oppretter `quota`-varsler, og synkene mot ClickHouse og GA4 (T22, T23) oppretter `integration_error`-varsler når tilstanden oppstår. T24 må evaluere de fire typene som leses ut av tallene, levere alle varsler og la noen kvittere. Spesifikasjonen sier ikke hvilke vinduer som sammenlignes, hva «runde» og «ikke-førsteplass» betyr, eller hvordan en leveringsfeil håndteres. ADR 0012 ber T24 om å bare varsle på dominerende modell, og om en egen trigger etter aggregeringen.

## Beslutning

### Når og hvordan

1. **`ekko:evaluate-alerts [--tenant=*] [--sync]`** legger én `EvaluateTenantAlerts`-jobb per aktiv tenant i køen (`--sync` kjører dem i prosessen, slik Cloud Scheduler gjør, `infra/terraform/scheduler.tf`). Jobben arver `TenantJob` og kjører med tenant-kontekst og RLS. Kommandoen er planlagt hver time, kvart over, etter `ekko:aggregate`.
2. **Én evaluering per tenant og lokal dag.** Jobben gjør ingenting før 05:30 i tenantens tidssone (`ekko.alerts.evaluate_after`). Den første kjøringen etter det tar dagen ved å sette inn en rad i `alert_evaluations` (unik på tenant og dato, så to samtidige kjøringer ikke evaluerer to ganger). Kjøringen evaluerer typene og leverer døgnmeldingen. Senere kjøringer samme dag prøver bare feilede kanaler på nytt. Pauset og stengt tenant evalueres ikke.
3. **Vinduer.** «Siste uke» er de sju dagene som slutter i går (i dag er ikke ferdig), «forrige uke» de sju før det, begge i tenantens tidssone. Tallene hentes fra `MetricsService`. Tre nye metoder dekker det `agg_daily` ikke har: `citationRounds()` (sitatrate per prompt, motor og dag), `competitorFirstPlace()` (førsteplass-rate per konkurrent og prompt, regnet fra nevnelsene siden aggregatet ikke har konkurrentposisjoner) og `unknownBrands()` (ukjente merkevarer, fra nevnelser av typen `unknown`). `dominantModels()` gir motorens dominerende modell-ID per lokal dag.
4. **Regler.** `alert_rules` opprettes første gang en type evalueres, med standardverdiene i `config/ekko.php` (terskel og kanalene `in_app`, `email`, `slack`). `visibility_drop`, `competitor_takeover`, `citation_lost` og `new_competitor` har regel per merkevare, `model_change` og `quota` per tenant. En regel som er slått av (`is_active = false`) gir ingen varsler. Tersklene står i enheten til `alert_rules.threshold`: prosentpoeng for `visibility_drop` (10) og prosent for `competitor_takeover` (50) og `new_competitor` (10). Alle sammenligninger er «mer enn», så et fall på akkurat terskelen varsler ikke.
5. **Karens.** Et varsel har et `subject` (for eksempel marked og motor). Samme `subject` på samme regel varsles ikke igjen innen 7 dager (30 for `new_competitor`), så en vedvarende endring gir ett varsel og ikke ett per dag.

### Hva hver type betyr

- **`visibility_drop`:** synlighet for et marked og en motor, siste uke mot forrige, fall over terskelen i prosentpoeng, n ≥ 30 begge uker (spec).
- **`competitor_takeover`:** per kjerneprompt (aktiv, `priority = core`) og aktiv konkurrent. Konkurrentens førsteplass-rate (andel listesvar der den står først) var på eller under terskelen forrige uke og er over den siste uke. Begge uker må ha minst 5 listesvar for prompten. «Ikke-førsteplass» i spesifikasjonen er tolket som «ikke over terskelen».
- **`citation_lost`:** per kjerneprompt og motor. En runde er en dag med svar (som for stabilitet, ADR 0012). Eget domene var sitert i forrige runde med svar (minst ett svar med eget sitat) og er ikke sitert i siste runde. Siste runde må være fra de tre siste dagene, så et prompt som er sluttet å kjøre, ikke varsler. Forrige runde letes opp 35 dager tilbake, så ukentlige og månedlige prompts dekkes.
- **`new_competitor`:** en merkevare som oppgis som ukjent (case-insensitivt navn) i mer enn terskelen av svarene i markedet siste uke, med minst 30 svar i markedet. Spesifikasjonen har ingen n-grense, men én ukjent nevnelse i et fåtall svar gir ellers falske varsler.
- **`model_change`:** motorens dominerende modell-ID for en dag (flest svar med modell-ID, ved likt antall forrige dags modell hvis den er blant dem, ellers den minste ID-en) er en annen enn dominerende modell-ID forrige dag med svar. Bare endringer de tre siste dagene varsles. `model_changes`-markørene brukes ikke til varsling: de merker første svar med en annen ID, og leverandører veksler mellom alias og datert versjon i løpet av en dag. Markøren kan også mangle når dominansen skifter uten at dagens første svar er nytt. Markørene brukes fortsatt av dashboardet (T26).
- **`quota`:** opprettes av `UsageRollup` ved 80 og 100 % (ADR 0013). Regelen får samme standardkanaler som de andre.
- **`integration_error`:** opprettes av synkene mot ClickHouse og GA4 når en synk feiler to kalenderdager på rad (T22, T23, spec 10). T24 leverer varselet og viser bare integrasjonstype og merkevare, aldri feilkode eller tilkoblingsdata. Synkene oppretter regelen selv med bare `in_app` hvis den mangler. Først når tenant-admin legger til `email` eller `slack` på regelen, går varselet ut den veien.

### Døgnmelding, levering og kvittering

6. **Døgnmelding.** Den første kjøringen hver lokal dag gir alle varsler uten `digest_date` dagens dato. Disse er døgnets melding: én Slack-melding til tenantens webhook (på tenantens `default_locale`) og én e-post per mottaker (på mottakerens språk, ellers tenantens). Varsler som oppstår senere på dagen, for eksempel et kvotevarsel fra timejobben, venter til neste døgn. Meldingen grupperer per type.
7. **Kanaler.** Kanalene følger regelen. `in_app` er varsellisten og trenger ingen levering. `slack` krever en webhook som er `https` mot en vert i `ekko.alerts.slack_hosts` (standard `hooks.slack.com`), uten port og brukerinformasjon, og kallet følger ikke omdirigeringer, slik at en lagret adresse ikke kan få serveren til å kalle en intern adresse (spec 16). Webhooken logges aldri. Slack-tekst escapes (`&`, `<`, `>`), så et merkevarenavn ikke kan lage en omtale eller lenke. E-post sendes umiddelbart fra jobben (en køet e-post ville vært en køklasse utenfor `TenantJob`, ADR 0005).
8. **Mottakere.** `recipient_roles` i varselets payload, standard `tenant_admin`. `tenant_admin` er eieren, tenantens admin og partnerrådgivere i partner-tenanten. `platform_admin` er alle plattformadmin. `quota` går til begge, de andre typene til tenant-admin.
9. **Kvittering per kanal.** `alerts.deliveries` har per kanal status (`sent`, `failed`, `gave_up`, `skipped`), antall forsøk og tidspunkt, og for e-post hvilke brukere som er sendt til. En feilet kanal prøves på nytt av neste timekjøring samme dag, høyst tre forsøk (`ekko.alerts.delivery_attempts`), og en retur sender bare til dem som ikke har fått meldingen. `skipped` betyr at ingen kan motta (ingen webhook, adressen avvist, ingen mottaker). `delivered_at` settes når ingen kanal venter.
10. **Kvittering fra person.** `POST /alerts/{alert}/acknowledge` setter `acknowledged_by` og `acknowledged_at`. Tenant-admin kan kvittere (`ManageAlerts`, AlertPolicy). Første kvittering gjelder.

### Datamodell

`alerts` får `subject`, `digest_date`, `deliveries` (jsonb) og `acknowledged_at`. Ny tabell `alert_evaluations` (tenant-eid, RLS). Spesifikasjonen (6.5, 7.1, 11.1) er oppdatert.

## Konsekvenser

- Avvik fra spesifikasjonen, ført inn i 11.1: n-grenser for `competitor_takeover` og `new_competitor`, tolkning av «ikke-førsteplass» og «runde», karens, og at `model_change` bruker dominerende modell.
- Et kvotevarsel som oppstår etter dagens evaluering, leveres neste morgen. Det er en følge av én melding per døgn. Skal 100 % av kvoten varsles med en gang, trengs en egen hastekanal.
- E-posttjeneste og avsender (A6) er ikke avklart. Koden bruker standard Laravel-mailer (`MAIL_MAILER`), og lokalt og i test er det `log` og `array`.
- `competitorFirstPlace()` og `unknownBrands()` leser `mentions` direkte i to uker om gangen, per merkevare. Det er greit ved dagens volum. Ved mye data bør de flyttes til aggregatet (nye kolonner i `agg_daily`).
- T22 og T23 skal ikke endres av denne oppgaven. Opprettes `integration_error`-regelen med bare `in_app`, kan T28 la tenant-admin velge kanaler. Standardkanalene ligger i `ekko.alerts.default_channels`.
- T28 bygger varselsiden og kvitteringsknappen på `alerts.acknowledge`.
