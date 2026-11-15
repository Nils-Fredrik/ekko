# 0014 – Daglige øyeblikksbilder for robottrafikk

- **Status:** Foreslått
- **Dato:** 2026-11-15
- **Board:** `task:20` / T22

## Kontekst

ClickHouse har et eget `site`-felt for domenet. Vanlige nginx- og Apache-logger i combined-format har det kun når request target er en absolutt URL. Web og køarbeider kjører i ulike Cloud Run-instanser.

## Beslutning

- ClickHouse-konfigurasjonen per merkevare bruker `host`, `database`, `table`, `user` og `password` i den eksisterende krypterte kolonnen. Bruk en lesebruker med tilgang begrenset til tenantens loggdata. `host` er vertsnavnet med eventuell port eller en full HTTPS-adresse uten sti. Driftsansvarlig godkjenner eksakte HTTPS-endepunkter gjennom `EKKO_CLICKHOUSE_ENDPOINTS` lokalt eller Terraform-variabelen `clickhouse_endpoints`. Omdirigering følges ikke.
- Synken teller foregående fullførte UTC-døgn med `site`, `ts`, `path` og `user_agent` i ClickHouse. `ekko:sync-bot-hits --date=ÅÅÅÅ-MM-DD` kan kjøre et tidligere UTC-døgn på nytt. Hvert valg av merkevare og dato erstattes atomisk etter vellykket synk, også når det nye resultatet er tomt.
- For merkevarer uten ClickHouse laster tenant-admin opp en komplett logg for ett registrert domene. Hvert UTC-døgn med gyldige rader erstatter tellingene for dette domenet. Deler av samme døgn må samles i én fil før opplasting. Grensene er 2 MiB komprimert og 64 MiB utvidet.
- Jobben får kun tenant- og opplastings-ID. Databasen deler den krypterte, størrelsesbegrensede filen mellom web og køarbeider. Jobben fjerner opplastingsinnholdet når filen er behandlet eller avvist. Den daglige synkkommandoen fjerner innholdet fra ubehandlede opplastinger som er eldre enn sju dager.
- Begge kilder klassifiserer etter første treff i den konfigurerte listen over user-agents. Kun aggregater lagres permanent. Baner i aggregatene har ingen query-parametere eller fragmenter. IP-adresser, referere og opprinnelige user-agents eksporteres ikke.

## Konsekvenser

En ny synk kan erstatte eldre dagsdata fra samme merkevare hvis ClickHouse blir aktivert senere.
