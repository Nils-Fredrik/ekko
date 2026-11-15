# 0021 – Fakturagrunnlag fra forbrukshendelser

- **Status:** Foreslått
- **Dato:** 2026-11-15
- **Board:** `task:24` / T31

## Beslutning

Plattformadmin får `/admin/billing` med måned, kundelinjer, påslag og fakturamottakersummer. CSV lastes fra `/admin/billing/export?month=YYYY-MM`. Vanlige tenantroller har ingen tilgang, og påslag skjules på serialiserte Team-modeller.

- Måneden er UTC med inklusiv start og eksklusiv slutt, samme avgrensning som `usage_monthly`. Eksporten leser `usage_events` direkte under separat tenantkontekst og RLS per kunde. Inaktive kunder med forbruk tas med; personlige team og kunder uten hendelser tas ikke med.
- Alle kostnader summeres som eksakte desimaltall. Plattformkostnaden får påslag, BYOK vises separat for avstemming og faktureres ikke. Kallkolonnen inkluderer begge betalingsmåter og alle hendelsestyper. Hendelser uten motor får `cost_other_usd`.
- `teams.billing_markup_percent` er nullable og kan bare endres gjennom det kontrollerte admin-endepunktet. Endringen revideres med gammel og ny verdi. NULL betyr uavklart A7 og blokkerer CSV; 0 er eksplisitt nullpåslag. Ingen sats settes automatisk.
- Partneren er fakturamottaker (`account_id`), kunden er fakturalinjen (`tenant_id`). Partnerens eventuelle eget forbruk er en egen linje. Sumkortene viser summen av de allerede avrundede kundelinjene. CSV har ingen ekstra sumrader som kunne blitt importert og fakturert en gang til.
- Sluttbeløpet per kundelinje avrundes én gang til to desimaler (HALF_UP). Kostnad og påslag vises med åtte desimaler for avstemming. Valutaen er USD; ingen valutakurs, merverdiavgift eller fast pakkepris er oppfunnet.
- CSV er UTF-8, kommaseparert og bruker CRLF med korrekt sitat-/linjeskift-escaping. Kolonnenavn er stabile importfelter i alle språk. Tekst som kan bli tolket som regnearkformel prefikses med apostrof.

## Avgrensning

Dette er et eksportgrunnlag, ikke en bokført faktura eller en historisk prisavtale. Pakke, partnerkobling og påslag er dagens konfigurasjon, også for en eldre måned. Adminsiden sier dette eksplisitt. Kontroller oppsettet før import. Et eventuelt behov for å låse fakturamåneder og lagre historiske kundeavtaler må spesifiseres separat.

A7-satsene må avklares av eieren. Det hindrer ikke testing av eksporten med eksplisitte syntetiske satser, og åpner ikke for å bruke syntetiske satser i produksjon.
