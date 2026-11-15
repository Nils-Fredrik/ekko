# Ytelsestest (T37)

Aksept krever en egen syntetisk tenant i produksjon med 365 dager × 100 prompts × 6 motorer × 3 gjentakelser = 657 000 svar, med p95 < 800 ms på dashboard-sidene. Testen er seriell, varm og måler hele autentiserte HTTP-responsen, uten nettleserrendering. Den beviser ikke kapasitet under samtidige brukere eller kalde instanser.

## Forutsetninger og datasett

Bruk den deployede revisjonen, migrert PostgreSQL, eksisterende motorkatalog og fungerende dashboard/REST. Dokumenter commit, Cloud Run-revisjon, CPU/minne/minimumsinstanser, Cloud SQL-type og hvor målingen kjøres. Sett av plass til tabeller, indekser og WAL; datasettet kan kreve flere GB. Kjør ikke generatoren i en HTTP-forespørsel.

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

Generator, dimensjoner, faktisk aggregering, tenant-isolert opprydding og avvisning av endrede identiteter er dekket av `tests/Feature/Performance`. Python-testene dekker p95, Inertia-parsing og avvisning av ufullstendige/utdaterte datasett og kjøres i CI. Full årsvolum og produksjonsp95 gjenstår til T10/T33 er deployet; ingen lokal rapport erstatter dette.
