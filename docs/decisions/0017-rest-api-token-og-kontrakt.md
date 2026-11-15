# 0017 – REST-API: tenantbundne tokens og avgrenset kontrakt

- Status: Foreslått
- Dato: 2026-11-15
- Arbeidspakke: T33, Board task:38

## Kontekst

Spesifikasjonens 15.1 krever Sanctum-tokens knyttet til bruker og tenant. Jetstreams standardtokens er bare knyttet til bruker, og nettleserens `current_team_id` kan endres etter opprettelse. Partnerbrukere kan ha tilgang til flere tenants. Eksisterende webmiddleware tillater partnerens samlede tenantsett i RLS, som er for vidt for ett tenantbundet API-token.

## Beslutning

Personlige tokens beholder Sanctums bruker-eierskap, slik at autentisering kan skje før tenantkontekst er etablert. Bindingen lagres som én serverstyrt ability `tenant:<id>`. Jetstream godtar bare `read`/`write` fra skjemaet og setter binding fra valgt tenant som brukeren faktisk kan velge. Tillatelsesredigering bevarer bindingen; den kan ikke endres eller legges til av klienten. Gamle ubundne tokens avvises og må erstattes. Wildcard gir ikke implisitt v1-tilgang.

V1 bruker egen middleware, uten webgruppens tenantvalg. Den krever et ekte personlig token, nullstiller kontekst og tilgangscache, kontrollerer fortsatt medlemskap/partnertilgang og åpen tenant, setter tenantkontekst og kjører forespørselen med nøyaktig én RLS-tenant i en transaksjon. Feil ruller tilbake transaksjonen. Rollene håndheves med eksisterende policyer, i tillegg til tokenets read/write. Plattformadminens tidsbegrensede «opptre som» kan ikke gi et varig API-token til en fremmed tenant.

API-et bruker eksplisitte ressursfelt, begrenset paginering og perioder på maks 366 dager. Metrikkene kommer fra MetricsService, med samme utvalg og beregninger som øvrige flater. Kalenderdager tolkes i tenantens tidssone. Promptskriving validerer relasjonene innen valgt merkevare, låser markedet før prompten i samme rekkefølge som MCP og bruker eksisterende revisjonslogg som maskerer prompttekst. PATCH/DELETE bruker id i JSON-body på stien som spesifikasjonen angir. Sletting følger databasens eksisterende kaskader; deaktivering bevarer data. Pakkegrensen teller aktive prompts og sjekkes ved opprettelse av aktive prompts og ved reaktivering. Redigering og deaktivering tillates også etter nedgradering til en pakke med lavere grense.

## Konsekvenser

Nettleserbytte påvirker ikke integrasjoner. Tilbakekalt medlemskap eller token får umiddelbar effekt på neste kall. Partnertokens kan ikke hente andre kunders data gjennom rå SQL. Eldre tokens slutter å fungere for nye v1-endepunkter, og tokensiden forklarer behovet for å opprette et tenantbundet token. Bindingen er intern og tilbys aldri som redigerbar permission.

Ingen migrering eller nytt tokenbibliotek kreves. MCPs OAuth-kontrakt er et eget ansvar i T34; denne endringen gjelder personlige REST-tokens. Grensene, svarformene, feilkodene og slettesemantikken står i docs/api.yaml og spec 15.1. Isolasjonssuiten dekker hver rute, rå SQL for partner, manipulerte relasjons-ID-er og rollback ved avvist tilgang.
