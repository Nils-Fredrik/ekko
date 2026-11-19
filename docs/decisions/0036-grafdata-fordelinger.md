# 0036 – Fordelinger i dagsaggregatene for grafdata

- **Status:** Foreslått
- **Dato:** 2026-11-19
- **Board:** task:86 / U03

## Bakgrunn

Grafene trenger sentiment fordelt på merkevarer og siteringer fordelt på eierskap og kildetype. En sentimentsum kan ikke rekonstruere denne fordelingen. Et domene kan ha flere kildetyper og endret eierskap i samme periode; én etikett på domenet taper informasjon. Eksisterende dagsaggregater har ikke disse fordelingene.

## Beslutning

- `agg_daily.sentiment_distribution` er et JSON-objekt med `own` og `competitors`. Førstnevnte inneholder `negative`, `neutral` og `positive` med antall sentimentberikede nevnelser for merkevaren. Konkurrentdelen er et objekt der hver relevant konkurrent-ID med kjent sentiment peker på de samme tre tellerne. Ukjent sentiment og ukjente entiteter teller ikke. Nevnelser for andre merkevarer eller konkurrenter under andre merkevarer teller ikke.
- Sentimentverdien lagres med tre desimaler på intervallet −1 til 1. I tråd med uttrekksprompten betyr negativ verdi negativ, null nøytral og positiv verdi positiv. Dette er en fortegnsinndeling, ikke et mål på styrken i ytringen.
- `agg_source_daily.citation_distribution` er et objekt med eierskap (`own`, `competitor`, `third_party`), der hvert eierskap inneholder kildetype og antall siteringer. En ukjent kildetype lagres som `unknown`, separat fra den eksplisitte kildetypen `other`. Alle kombinasjoner beholdes, også når ett domene har flere typer eller eierskap i perioden. Disse teller siteringer, mens `responses_with_domain` fortsatt teller forskjellige svar med domenet.
- Begge kolonnene er nullable. `NULL` betyr at den historiske aggregatraden ennå ikke er beregnet etter denne beslutningen; det betyr ikke null nevnelser eller null siteringer. Tomme fordelinger etter en faktisk beregning er data, ikke ukjent historikk. Lesere må kontrollere at **alle** relevante rader er beregnet før de presenterer en komplett fordeling. En blandet periode skal ikke vises som en komplett fordeling med bare de kjente radene.
- `DailyAggregator` beregner fordelingene samtidig med øvrige tellere, under eksisterende tenant-kontekst og radbeskyttelse. Den kan idempotent kjøres om igjen for en historisk dag med eksisterende `ekko:aggregate`. Migrasjonen gjør ingen automatisk historisk omberegning. Historikk kan bare gjenskapes der svar, nevnelser og sitater fortsatt finnes; manglende datagrunnlag krever eksplisitt håndtering, ikke antatte nullverdier.

## Konsekvenser

Kjøretiden øker med antall nevnelser og sitater som allerede leses under dagsaggregeringen, men grafene kan beregnes fra aggregatene uten å skanne alle rå nevnelser og sitater. Inntil historikken er kontrollert og beregnet, må grafene kunne vise at fordelingen ikke er komplett. Videre bruk av de nye feltene skal beholde tenant-, filter- og n-reglene i metrikktjenesten.
