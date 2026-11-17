# T36 – Gjenoppretting og overvåkingsbevis

Dato: 17.11.2026. Board: task:36. Prosjekt: `ekko-514612`.

## Gjenoppretting

- Kilde: `ekko-postgres`, PostgreSQL 16, `europe-west1`.
- Vellykket on-demand backup: `1794923120785`, ferdig 13:46:52 UTC.
- Separat mål: `ekko-restore-t36-20261117`, Enterprise `db-g1-small`, 20 GB SSD,
  samme region, uten backup/final backup. Merket `purpose=restore-drill,task=t36`.
- Opprettelse `e3027f4c-af6b-4e75-b913-22b100000024`: DONE 14:53:21 UTC.
- Restore `565ae67d-1c52-442c-8a81-cb8200000024`: DONE 14:57:22 UTC.
- Påkrevd connector og `ENCRYPTED_ONLY`, ingen autoriserte IP-nettverk.
- `scripts/operations/verify-restore.py` bestod mot målet: gjenopprettet
  migreringsskjema, FORCE RLS og policyer på tenanttabeller, app-rolle uten
  privilegerte attributter/medlemskap, migreringsrolle med BYPASSRLS, syntetisk
  isolasjon uten tenant og mellom tenant 101/102. Proben ble rullet tilbake.
- Verifikatoren avviser `--instance ekko-postgres` før nettverk/hemmelighetslesing
  med exitkode 2. Ingen faktisk kunderad ble lest og ingen app eller jobb ble
  koblet til kopien. Migreringspassordet ble brukt i minnet, aldri skrevet ut.
- Sletting: `2ecc8fb9-5592-495d-b6dd-b59000000024` startet 15:01:55 UTC.
  DONE 15:03:22 UTC; instansen er slettet.

Dette beviser gjenoppretting av en vanlig backup, database­roller og isolasjon.
Det er ikke en PITR-øvelse, trafikkflytting eller gjenoppretting av GCS-objekter.
RTO for en full hendelse og faktisk e-postleveranse kan ikke utledes av dette.

## Terraform og applikasjon

En full plan viste eksisterende drift i Cloud Run-konfigurasjon og tilbakekalling
av web-granten til migreringshemmeligheten (egen T39-F1). Den ble ikke anvendt.
T36-planen er derfor avgrenset og gjennomgått: 11 opprettelser, ingen endring
eller sletting av eksisterende ressurser. Åtte varslingspolicyer, e-postkanal,
heartbeat-metrikk og separat Scheduler-plan som først er pauset.

Live aktivering, heartbeat og varslingsprøve dokumenteres på Board task:36
etter review/merge og deploy. Denne filen beskriver bevisene før aktivering.
