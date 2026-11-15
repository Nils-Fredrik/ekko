# T17 – Dispatcher, kø og worker

Implementert: deterministisk dagsplan, flettet PostgreSQL-kø, fersk kvotekontroll, motorgrenser, synkron innsamling og DataForSEO-polling med én ny innsending, forbruksføring før rålagring, annotasjoner, ekstraksjonskø og OIDC-beskyttet Scheduler-tick. Cloud Run-start har aktiv-kjøring-kontroll og 1–8 tasks.

Se ADR 0020 for valg og begrensninger. Produksjonsverifikasjon avventer T10/bootstrap. Oppgaven lukkes først når eieren har merget en godkjent PR med grønn CI.
