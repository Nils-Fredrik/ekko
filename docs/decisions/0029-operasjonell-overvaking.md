# 0029 – Operasjonell overvåking

- Status: Foreslått
- Dato: 2026-11-17
- Board: task:36 / T36

Cloud Monitoring bruker plattformens egne metrikker for HTTP-feilrate, feilede
Cloud Run-eksekveringer og SQL CPU/disk. For applikasjonssignalene måles tilstanden
fra PostgreSQL, uten å eksportere kundeinnhold eller kopiere tenanttabellene til
et eget overvåkingslager.

`ekko:monitor-health` leser aktive og pausede tenants én om gangen, i eksisterende
TenantContext og TenantRls. Den summerer terminale innsamlinger siste 24 timer per
motor og teller uferdig innsamling eldre enn seks timer og uttrekksbatcher eldre
enn 24 timer. Kvotestopp og lukkede tenants inngår ikke i motorfeilraten. Raten
vektes etter antall kjøringer. Run-batchenes tellere kan være utdaterte mellom
dispatcher-kjøringer, så forsinkelse bestemmes av faktiske ventende kjøringer.
Umiddelbare veiviserbatcher bruker bekreftelsestidspunktet som `scheduled_for`,
mens `dispatch_date` fortsatt er tenantens lokale dato. De blir ikke forsinket
bare fordi bekreftelsen skjer etter klokken seks.

Et globalt kommandoløp logger bare numeriske avvik med faste signalnavn og
interne motornøkler. Hele løpet må lykkes før en heartbeat og 30-minutters
motorstatus for plattformadmin publiseres. Ingen delt tenant-prop eksponerer
plattformstatusen. T30 må vise manglende status som ukjent. En Cloud Logging-
metrikk og fraværspolicy oppdager stopp i kontrollen etter første heartbeat.

Scheduler-planen er separat fra innsamlingens pausebryter. Den opprettes pauset
og aktiveres først etter at kommandoen er utrullet. Eksisterende `ekko-task` og
Scheduler-identitet brukes; ingen nye runtime-hemmeligheter eller IAM-roller
kreves. Applikasjonssignaler bruker loggvarsler med én melding per time og
automatisk lukking etter en time uten treff. Runbook krever fersk kontroll før
en hendelse erklæres løst.

Gjenoppretting øves mot en separat instans i samme EU-region, med påkrevd TLS og
Cloud SQL connector. Skjema, roller og FORCE RLS verifiseres med syntetiske rader
i en transaksjon som rulles tilbake. Produksjonsrader leses ikke. Instansen
slettes etter øvelsen; den kobles aldri til app, jobber eller Scheduler.
