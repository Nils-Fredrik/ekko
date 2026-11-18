# Ytelsestest (T37)

Aksept krever en egen syntetisk tenant i produksjon med 365 dager × 100 prompts × 6 motorer × 3 gjentakelser = 657 000 svar, med p95 < 800 ms på dashboard-sidene. Testen er seriell, varm og måler hele autentiserte HTTP-responsen, uten nettleserrendering. Den beviser ikke kapasitet under samtidige brukere eller kalde instanser.

## Forutsetninger og datasett

Bruk den deployede revisjonen, migrert PostgreSQL, eksisterende motorkatalog og fungerende dashboard/REST. Dokumenter commit, Cloud Run-revisjon, CPU/minne/minimumsinstanser, Cloud SQL-type og hvor målingen kjøres. Sett av plass til tabeller, indekser og WAL; datasettet kan kreve flere GB. Kjør ikke generatoren i en HTTP-forespørsel.

`ProductionEngineSeeder` oppretter hele motorkatalogen, også de fem utsatte
motorene som deaktiverte oppføringer. Fixturen kan bruke disse til syntetiske
historiske svar uten å aktivere motorene, opprette priser eller kalle leverandører.

Kjør fra en kontrollert kommandosesjon med applikasjonens databaseoppsett. Ikke skriv databasepassord, lesepassord eller API-token i logger/Board. Eksporter et tilfeldig passord på minst 24 tegn som `PERF_PASSWORD` fra en hemmelighetskanal. Bruk én ny UUID per kjøring og noter den før oppretting, slik at også avbrutte opprettinger kan ryddes opp:

```sh
fixture_key=$(python3 -c 'import uuid; print(uuid.uuid4())')
printf '%s\n' "$fixture_key"
php scripts/performance/fixture.php create "$fixture_key" > fixture-created.json
php scripts/performance/fixture.php verify "$fixture_key" > fixture-verified.json
```

Generatoren lager en isolert tenant, en eier med ukjent tilfeldig passord, en verifisert lesebruker, én merkevare, ett marked og 100 prompts. Marked og prompts er inaktive, og det opprettes ingen motorkonfigurasjoner, jobber, råfiler eller fakturerbart forbruk. Det sendes ingen e-post eller leverandørkall. Seks eksisterende motorer brukes uendret. Hvert svar har en syntetisk tekst på 2100 tegn, én egenhenvisning, og to av tre gjentakelser har merkevareomtale. Den faktiske `DailyAggregator` bygger dagsaggregatene. Hver dag lagres atomisk under tenant-kontekst og RLS; en avbrutt oppretting må slettes før ny oppretting med samme UUID.

Forventede kontrollverdier: 657 000 runs/svar/henvisninger, 438 000 omtaler, 219 000 `agg_daily`, 2190 `agg_source_daily`, 365 datoer, seks motorer og tre gjentakelser. Summen av aggregerte svar er 657 000. Datoene ender på dagens UTC-dato. Kjør målingen samme dag.

## HTTP-måling

Bruk leserens e-post fra `fixture-created.json` som `PERF_EMAIL`, det samme `PERF_PASSWORD` og et kortlivet read-token bundet til den syntetiske tenanten som `PERF_REST_TOKEN`. Tokenet må ha `read` og `tenant:<tenant_id>` (T33). Lagre det kun i den kontrollerte sesjonen, aldri i rapporten. Opprett tokenet gjennom applikasjonens tokenadministrasjon eller en kontrollert konsoll; tilbakekall det etter målingen.

```sh
python3 scripts/performance/measure.py https://<produksjonsvert> \
  --fixture fixture-verified.json --samples 100 --warmup 5 \
  --report performance-result.json
```

Verktøyet kontrollerer datasettets dimensjoner, fullstendige aggregater og datoer før pålogging. Det måler `/dashboard`, merkevaresiden med 7/28/90 dager og REST-metrikker. Dashboard-verdiene må inneholde forventet antall svar; en tom side, innloggingsside eller redirect kan aldri gi en grønn måling. API-kall pauses til under 60/minutt utenfor målt tid. p95 er nearest-rank. Alle målte ruter må ligge under 800 ms for returkode 0. Rapporten inneholder ikke passord, token, cookies eller responsinnhold.

`--allow-small-fixture` og `--dashboard-only` er kun for lokal verifisering av verktøyet. Rapporten merkes `partial: true`; slike resultater er ikke akseptbevis. Selv `partial: false` må knyttes til dokumentert produksjonsrevisjon og instansoppsett før T37 kan lukkes.

## Opprydding

Etter måling, også ved feil:

```sh
php scripts/performance/fixture.php delete "$fixture_key"
php scripts/performance/fixture.php verify "$fixture_key"
```

Sletting krever den nøyaktige UUID-en og både syntetiske identiteter. Den avvises hvis ekte medlemmer, andre teamtilknytninger, kundetenanter eller plattformrettigheter er lagt til. Tenantens rader slettes gjennom fremmednøklenes kaskader under RLS; token, sesjoner og begge syntetiske brukere fjernes. Siste `verify` skal feile med manglende tenant. Fjern lokale hemmelighetsfiler og eksporter også. Behold bare den anonymiserte målerapporten og revisjons-/instansopplysningene som testbevis på Board.

## Verifisert før produksjon

Generator, dimensjoner, faktisk aggregering, tenant-isolert opprydding og avvisning av endrede identiteter er dekket av `tests/Feature/Performance`. Python-testene dekker p95, Inertia-parsing og avvisning av ufullstendige/utdaterte datasett og kjøres i CI. Full produksjonsmåling 17.11.2026 på revisjon `ekko-web-r44158308567-1` med årsvolumet ga p95 473,75 ms på oversikten, 510,89/582,73/811,75 ms på merkevaresiden (7/28/90 dager) og 439,26 ms på REST. 90-dagersvisningen oppfylte ikke kravet. Den syntetiske tenanten, brukerne, tokenet og den midlertidige generatorjobben ble slettet og fravær kontrollert. Task:37 ble derfor holdt åpen frem til sluttmålingen nedenfor.

Oppfølgingen samler `MetricsService::voiceParts()` sine tellere for svar, merkevare og aktive konkurrenter i én filtrert `agg_daily`-spørring. Tidligere krevde dette to skanninger pluss én per konkurrent; nå brukes to databasekall totalt (konkurrentliste og aggregat), også når listen vokser. Formler, filtre og tenant-kontekst er uendret. Metrikktestene kontrollerer tallene, tomme perioder, JSON uten konkurrentobjekt og fast antall spørringer. Produksjonsmålingen av denne første rettingen er dokumentert nedenfor.

## Oppfølging av 90-dagersvisningen 18.11.2026

Etter PR #84 ble hele årssettet målt fra Cloud Run i `europe-west1` mot offentlig HTTPS på revisjon `ekko-web-r44219246471-1`. Klientplasseringen er forskjellig fra målingen 17.11, så tidene er ikke en direkte før/etter-sammenligning. 100 prøver og fem oppvarminger per rute ga p95 295,71 ms på oversikten, 371,97/380,94/1548,64 ms på merkevaresiden (7/28/90 dager) og 281,62 ms på REST. Serverloggene bekreftet de langsomme 90-dagerskallene. Testtenant, brukere, token, midlertidige jobber og hemmelighet er slettet og fravær kontrollert (Board task:37, post #762).

Separat SQL-profilering viste fem aggregatspørringer for siden, med korrekt indeksbruk. `MetricsService::dashboard()` samler nå gjeldende periodes total, dagserie, motorfordeling og konkurrentandeler i én aggregatspørring med PostgreSQLs [GROUPING SETS](https://www.postgresql.org/docs/16/queries-table-expressions.html#QUERIES-GROUPING-SETS). Sammenligningsperioden leses fortsatt separat. Det reduserer siden fra fem til to aggregatspørringer. Hvert grupperingsnivå summerer tellere før de eksisterende formlene brukes; prosentverdier gjennomsnittsberegnes ikke. Filtre, tenant-scope/RLS, nullverdier, aktive konkurrenter og responsformat beholdes. Det innføres ingen cache eller endringer i databasekapasitet eller innstillinger.

Regresjonstestene kontrollerer håndberegnede tall, samsvar med de individuelle metrikk-API-ene for alle filterdimensjoner, tomme perioder, JSON uten konkurrentobjekt, flere konkurrenter og gjenbruk av tjenesten på tvers av tenant-kontekster. Rettingen ble levert i [PR #89](https://github.com/Nils-Fredrik/ekko/pull/89), med full HTTP-måling etter deploy som dokumentert nedenfor.

## Bestått produksjonsmåling 18.11.2026

T37s ytelseskrav er oppfylt med hele årssettet. Målingen startet 17:22:43 UTC fra en midlertidig Cloud Run-jobb i `europe-west1` mot `https://ekko.maksimer.com`. Uendret `scripts/performance/measure.py` kjørte fem oppvarminger og 100 målte forespørsler per rute, validerte svarvolumet i hver respons og rapporterte `partial: false`.

| Rute | p95 (ms) | Maks (ms) | Krav p95 < 800 ms |
|---|---:|---:|---|
| Oversikt `/dashboard` | 357,70 | 472,83 | Bestått |
| Merkevare, 7 dager | 349,43 | 412,63 | Bestått |
| Merkevare, 28 dager | 398,45 | 620,80 | Bestått |
| Merkevare, 90 dager | 492,93 | 1650,48 | Bestått |
| REST-metrikker, 28 dager | 312,62 | 432,36 | Bestått |

Dette er p95 for varme, serielle HTTP-kall inklusive full respons. Maksimum på 90-dagersvisningen viser at enkeltkall fortsatt kan overstige 800 ms. Testen gir ingen garanti for kaldstart, samtidige brukere, nettleserrendering eller klienter i andre regioner.

Målt main-commit er `23de8a195b01c8ede1a32cb9bd84ec964e7d62c1`, som inneholder PR #89 (`500ec37500bf1019d764b32ab9df607eb4196cf9`). [CI/deploy 44351886984](https://github.com/Nils-Fredrik/ekko/actions/runs/44351886984) var grønn før målingen. Aktiv revisjon `ekko-web-00041-clq` hadde 100 % trafikk og samme image-digest som CI-revisjonen `ekko-web-r44351886984-1`: `sha256:94cff2c7338406a0ac2324768fbdbed4c332e13458126898bb3d1da93132f825`. Mellom disse revisjonene var kun miljøvariabelen `EKKO_CLICKHOUSE_ENDPOINTS` endret; resten av runtime-spesifikasjonen var identisk. Aktiv revisjon, digest, main-commit, trafikk og konfigurasjon var uendret før/etter målingen.

Web kjørte med 1 vCPU / 1 GiB, concurrency 80, min 0 / maks 4 instanser og CPU uten throttling. Cloud SQL var PostgreSQL 16, `db-g1-small`, 20 GB SSD, ZONAL i `europe-west1`. Kapasiteten ble ikke økt for testen.

Den isolerte testtenanten hadde 365 dager (19.11.2025–18.11.2026), 100 prompts, seks motorer og tre gjentakelser. Verifikasjonen viste 657 000 runs/svar/sitater, 438 000 omtaler, 219 000 dagsaggregater, 2190 kildeaggregater og 657 000 aggregerte svar. Generatoren brukte applikasjonens vanlige `ekko_app`-rolle med RLS og den faktiske `DailyAggregator`, uten leverandørkall eller kundedata.

Oppryddingen var bekreftet 17:29:26 UTC: testtenant og begge testbrukere var borte (0/0). Den reviewede slettefunksjonen fjernet tenantdata, token og sesjoner. Alle fire midlertidige jobber (generator, klargjøring, måling og sletting) og den midlertidige Secret Manager-hemmeligheten ble deretter slettet, med fravær kontrollert via API. Alle fire jobbutførelser fullførte med suksess. [Maskinlesbart måle- og oppryddingsbevis](performance/t37-2026-11-18.json) inneholder bare syntetiske identifikatorer, måletall og driftsmetadata; ingen hemmeligheter eller responsinnhold. Board: task:37 og dokumentasjonsoppfølging task:70.

Lokale kontroller av applikasjonskoden i PR #89 og den påfølgende dokumentasjons-PR #90 var grønne: `composer test` (2053 tester, 11 716 assertions), `composer lint` og `npm run lint` (fire eksisterende `v-html`-advarsler). PR #89 hadde også grønn frontend-build og tre Python-tester av måleverktøyet. Denne oppfølgingen endrer kun dokumentasjon og målebevis.
