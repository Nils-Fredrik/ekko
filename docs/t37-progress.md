# T37 – ytelsestest (påbegynt)

Board task:37. Gren `t37-performance-test`, fra origin/main dfa284f.

scripts/performance/measure.py logger inn med PERF_EMAIL/PERF_PASSWORD og bruker PERF_REST_TOKEN separat for API. PERF_BRAND_ID må peke på en merkevare i den syntetiske testtenanten. Den avviser redirects/feilsider, leser hele responsen og beregner nearest-rank p95. Standard er 5 oppvarmingskall og 100 målte kall per rute. API-kallene pauses utenfor målt tid for å holde seg under 60/minutt. Rapporten inneholder bare rute, tidspunkt og latenstall, ingen cookies, nøkler eller responsinnhold.

Lokal verifisering 15.11.2026 mot integrasjon av T33/T34: 20 kall per rute på en minimal syntetisk tenant. p95: oversikt 31,19 ms, merkevare 33,84 ms, API 49,51 ms. Dette verifiserer verktøyet og er ikke et produksjonsresultat eller akseptbevis. To enhetstester dekker p95 og Inertia-parsing.

Gjenstår: reproduserbart årsdataoppsett (365 dager × 100 prompts × 6 motorer × 3 repetisjoner = 657 000 svar), dagsaggregater og test av datasettets dimensjoner. Kjør deretter mot egen syntetisk tenant i produksjon, dokumenter instans-/databaseoppsett og p95 < 800 ms, og slett testtenanten med kontrollert opprydding. T10 har ennå ingen kjørbar Cloud Run-tjeneste; T33 må også integreres. Ingen produksjonsdata er opprettet eller slettet.
