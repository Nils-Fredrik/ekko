# T31 – fakturagrunnlag (påbegynt)

Board task:24. Gren `t31-billing-export`, fra origin/main dfa284f.

Første del er BillingTotals med eksakt desimalregning: kall telles for både plattform og BYOK, plattformkostnad grupperes per motor, BYOK vises separat, og påslag brukes bare på plattformkostnad. Fakturabeløpet avrundes én gang etter eksakt påslag. Ukjent påslag gir null fakturabeløp; eksplisitt 0 er en gyldig sats. Tre tester dekker avstemming, manglende sats og grensen for dobbeltavrunding.

Gjenstår: månedlig uttrekk direkte fra usage_events i UTC, én tenant om gangen under RLS; påslagsfelt med adminstyring og audit; partnergruppering med én kundelinje; formelsikker CSV med stabile kolonner og eksakte beløp; plattformadmin-endepunkt og isolasjonsregister; avstemming mot testmånedens faktiske usage_events. Bare beregningsdelen er implementert, ikke en tilgjengelig eksport.

A7 (priser og påslag) er ikke avklart. Satsene skal være eksplisitt konfigurert før fakturagrunnlaget anses komplett. Ingen økonomiske transaksjoner eller reelle fakturaer er opprettet.
