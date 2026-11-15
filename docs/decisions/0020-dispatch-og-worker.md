# 0020 – Varig dispatch og worker

- **Status:** Foreslått
- **Dato:** 2026-11-15
- **Board:** `task:14` / T17

## Kontekst

Dispatch må tåle gjentatte Scheduler-kall. Flere worker-prosesser må dele kvoter og motorgrenser, og forbruk skal ikke forsvinne når lagring av et betalt svar feiler.

## Beslutning

- Ett planlagt batch per marked og dato i tenantens tidssone. `dispatch_date` er nullable for å bevare manuelle/eksisterende batcher. Ukedag følger CRC32 og Carbon sin søndag=0, månedsdag er 1–28.
- PostgreSQL-køinnsetting og `runs.enqueued_at` skrives i samme transaksjon. Køforbindelsen må være samme forbindelse som tenant-data, uten `after_commit`. Rundgangen fletter tenantene. Worker sjekker fersk kvote på nytt.
- Motorens `config.concurrency` og `config.calls_per_minute` begrenser samtidighet og antall innsamling-/polloperasjoner per rullende minutt, globalt på tvers av tenants. Standard er 2 og 30. Adapterne eier HTTP-retry (maks tre forsøk på 429/5xx); en polloperasjon kan gjøre både ready- og fetch-kall. Grensene må derfor settes under leverandørens HTTP-grenser. Delte tidsbegrensede låser ligger i PostgreSQL; `ON CONFLICT DO NOTHING` gjør låsekonflikt trygg også i en transaksjon.
- Kjente fakturerbare svar registreres før GCS-lagring. Hendelsen knyttes til reservasjonstokenet, slik at fersk kvotekontroll ikke summerer både hendelse og samme reservasjon. Nytt leverandørkall får nytt token og ny forbruksrad. Vi oppfinner ikke forbruk for HTTP-feil uten kjent fakturerbart svar.
- Køleverandørens ID, innsendingstid, historiske pris og konto lagres. Kontonøkkelen er kryptert og skjult på modellen, aldri i køpayload. Dette gjelder også plattformkontoen, slik at nøkkelrotasjon ikke bytter konto under polling. Hver innsending belastes én gang; polling belastes ikke. Ventende oppgave sendes på nytt én gang etter seks timer dersom fersk kvote tillater det. Etter ytterligere seks timer markeres kjøringen feilet. Nøkkelen fjernes ved fullført eller terminal kjøring.
- `/internal/tick/extraction-poll` har ingen sesjonsautentisering. Kun Google-signert OIDC med riktig issuer, audience, utløp og verifisert Scheduler-konto slipper gjennom. Ruteregisterets `service`-klasse dekkes av signatur- og avvisningstester.
- Worker-start undersøker alle sider av Cloud Run-kjøringer under felles lås. Aktiv kjøring hindrer ny start. Antall tasks er `min(8, ceil(tilgjengelige jobber / 2000))`. `run.executions.list` gis kun på worker-jobben i tillegg til eksisterende executor-rettighet.

- Feil isoleres per tenant og per køinnsetting. Gyldige jobber startes selv om andre feiler; kommandoen returnerer feilkode og pollruten 503 etter at worker er startet. Loggen inneholder tenant-/run-ID og feilklasse, ikke potensielt sensitive unntakstekster.
- `CollectEngineRun` er det eksplisitte unntaket fra `TenantJob` sin ytre `ApplyTenantRls`-transaksjon (ADR 0005). Den arver fortsatt `TenantJob` og setter tenantkontekst; hvert databaseavsnitt i jobb og collector bruker egen `TenantRls::run`. Nettverkskallet skjer mellom transaksjonene for å unngå langvarige tenantlåser. Dette mønsteret skal ikke kopieres til vanlige jobber.

## Konsekvenser og begrensninger

Krasj etter at en leverandør har akseptert et kall, men før databasen har mottatt svaret/oppgave-ID-en, kan ikke gjøres eksakt én gang uten leverandørens idempotensstøtte. Lokal kø og reservasjoner hindrer vanlige duplikater, men et tvetydig nettverks-/prosessutfall krever avstemming mot leverandørens faktura. Ingen råsvar eller nøkler logges som feilbevis.

T17 leverer koden og lokale tester. Faktisk Cloud Run-start og Scheduler-OIDC i produksjon verifiseres sammen med T10 etter bootstrap; eksisterende schedules forblir pauset til dette er gjort.
