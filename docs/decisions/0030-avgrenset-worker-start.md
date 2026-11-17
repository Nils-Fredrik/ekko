# ADR 0030 – Avgrenset worker-start og påkrevd SQL-connector

Dato: 17.11.2026. T39-F8, Board task:57.

## Bakgrunn

Web kunne starte `ekko-worker` med `roles/run.jobsExecutorWithOverrides`.
En kompromittert web-prosess kunne dermed endre miljøvariabler for workeren,
som har tilgang til råsvar og leverandørnøkler. Cloud SQL tillot dessuten
ukryptert direkte tilkobling dersom nettverksadgang senere ble gitt.

## Beslutning

- Bare dispatch-identiteten beholder worker-start med task-count-overrides og
  lesing av worker-eksekveringer.
- Web får `roles/run.jobsExecutor` og lesing av eksekveringer kun på `ekko-wake`.
  Jobben kjører fast `php artisan ekko:dispatch-due --poll` med dispatch-identitet.
  Web sender et tomt JSON-objekt; IAM tillater ikke overrides.
- Veiviserens bekreftelse og analyse starter denne jobben etter commit.
  `extraction-poll` starter samme jobb, også når køen ennå er tom: polling kan
  opprette nytt arbeid. Ingen av disse inngangene starter ny dagsplanlegging.
- Oppstart serialiseres med egen databaselås på 180 sekunder, med margin for
  tokenhenting, listefrist og siste API-kall. Aktive eksekveringer kontrolleres
  med paginering. En aktiv jobb gir ingen ny oppstart. Feil ved oppstart fra
  Scheduler gir HTTP 503; senere jobbfeil overvåkes av T36.
- Daglig dispatch venter inntil 300 sekunder på den felles dispatch-låsen.
  Poll som møter en opptatt lås, returnerer uten ny planlegging og kontrollerer
  fortsatt om workeren må startes. Dermed mister ikke normal samtidighet ved
  kl. 01 dagsplanen eller utløser en falsk jobbfeil. En fastlåst dispatch gir
  fortsatt feil og driftsvarsel etter ventefristen.
- Cloud SQL beholder offentlig IPv4 for de eksisterende connectorene, uten
  autoriserte IP-nettverk, men krever `connector_enforcement = REQUIRED` og
  `ssl_mode = ENCRYPTED_ONLY`. Cloud Run bruker fortsatt connectorens Unix-socket;
  connectoren krypterer nettverkstransporten uavhengig av klientens lokale socket.

## Konsekvenser og utrulling

Ekstra jobbstart kan øke tiden før en kald worker begynner. Workerens kø-, kvote-
og tenant-isolasjon er uendret. En kompromittert web-prosess kan fortsatt vekke
poll-dispatcheren, men kan ikke velge kode, kommando, miljø eller worker-identitet.

Etter godkjent review opprettes `ekko-wake` med siste deployede jobs-digest,
webs begrensede start-/lesetilgang og CI-tilgang. Dette gjøres før merge fordi
`deploy.sh` også skal oppdatere den nye jobben. Worker-grantene til web fjernes
først etter at ny web-kode er deployet og verifisert. SQL-endringen planlegges
separat fra datalasting/måling. Kun gjennomgåtte, avgrensede planer anvendes;
øvrig workload-drift skal ikke følge med. Den gjennomgåtte planen setter også
T36s allerede vedtatte `LOG_LEVEL=info` på de fire eksisterende jobbene.
Kommandoer, image-digester og øvrige miljøverdier endres ikke av dette.
