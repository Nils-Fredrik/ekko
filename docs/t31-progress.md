# T31 – Fakturagrunnlag

Implementert månedlig adminside og CSV fra usage_events under tenant/RLS. Eksakte desimaler, partnergruppering med én linje per kunde, BYOK-avstemming, validering og revisjonslogg for eksplisitt påslag, CSV-formelvern og nb/sv/en.

A7 er fortsatt uavklart: ingen produksjonssats er satt, og manglende sats blokkerer eksport. Se ADR 0021 for importformat, avrunding og begrensningen til dagens kundeoppsett. Oppgaven lukkes etter eierens merge av godkjent PR med grønn CI.
