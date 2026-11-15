# 0015 – GA4-synk

- **Status** Akseptert
- **Dato** 2026-11-15
- **Board** `task:21` / T23

## Kontekst

Spesifikasjonen §10.2 krever daglige GA4-tall per kilde. Google har erstattet Data API-metrikken `conversions` med `keyEvents`. Google kan oppdatere tall opptil 48 timer etter innsamling. En felles tjenestekonto med tilgang til flere kunders properties ville gjort en property-ID fra én tenant tilstrekkelig til å be om tall fra en annen tenant.

Se [Data API-endringslogg](https://developers.google.com/analytics/devguides/reporting/data/v1/changelog#2024-05-06_new_dimensions_for_key_events) og [oppdatering av GA-data](https://support.google.com/analytics/answer/12233314).

## Beslutning

- Hver integrasjon bruker en egen Google-tjenestekonto med rollen Viewer på den aktuelle GA4-propertyen. Google Analytics Data API aktiveres i tjenestekontoens Google Cloud-prosjekt. Integrasjonen lagrer `property_id` og `service_account.client_email` og `service_account.private_key` i kryptert `config`. OAuth bruker kun lesetilgang, og endepunktene for både token og rapport er faste. Se [Googles oppsett for Data API](https://developers.google.com/analytics/devguides/reporting/data/v1/quickstart).
- `ekko:sync-ga4` kjører synkront i den eksisterende Cloud Run-jobben. Den laster integrasjoner for aktive merkevarer i aktive tenants med tenant-kontekst og RLS, og hver integrasjon synkroniseres med `TenantJob`.
- Synken ber om de tre siste avsluttede kalenderdatoene i tenantens tidssone. GA4 teller data for datoene i propertyens egen rapporteringstidssone. Kildene må matche en verdi i konfigurasjonen eksakt. Metrikken `keyEvents` lagres i `ai_referrals_daily.conversions`. En komplett rapport erstatter tall i perioden. I en rapport merket som samplet, terskelbegrenset eller med aggregerte kilder kan synken oppdatere radene som kom tilbake, og bevarer eksisterende rader som mangler i rapporten.
- Ved feil beholdes tidligere tall og `last_sync_at`. Synken registrerer siste feildato i tenantens tidssone. To kalenderdager på rad med feil oppretter et `integration_error`-varsel gjennom merkevarens regel eller tenantens felles regel. Hvis en regel mangler, opprettes en regel for varsellisten. Én integrasjon kan registrere ett varsel per dag. En vellykket synk nullstiller feilhistorikken for terskelen. Ved en uventet jobbfeil registreres feilen i en ny tenant-transaksjon etter tilbakerulling. Kommandoen fullfører de øvrige integrasjonene og avslutter med feilkode.

## Konsekvenser

T25 og T28 kan opprette og redigere kryptert GA4-konfigurasjon med feltene over. T24 leverer varsler og samler dem per tenant og døgn etter §11.1. Drift kan bekrefte vellykkede synker med `integrations.last_sync_at` og se feilkoder i logg og varsel.
