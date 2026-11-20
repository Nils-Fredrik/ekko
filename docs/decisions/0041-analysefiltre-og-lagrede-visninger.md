# 0041 – Analysefiltre og lagrede visninger

Status: foreslått i U11 (task:94).

## Bakgrunn

Board #914 beskriver felles filtre, delbare URL-er og lagrede visninger. De enkelte analysesidene tolket tidligere perioder og dimensjoner hver for seg. De delte grafcachene skilte ikke mellom forskjellige sammenligningsperioder.

## Beslutning

`AnalysisFilters` normaliserer tillatte dimensjoner mot merkevarens ressurser. `DashboardPeriod` tolker datoene i tenantens tidssone. En egendefinert periode kan være høyst 366 dager. Samme periode i fjor bruker kalenderdatoer med 29. februar klippet til 28. februar. Ulike vinduer eller sammenligninger deler ikke innsikts-/sammenligningscache.

Innsiktens dagskvote, lagringsdag og genereringsdato følger dagens dato i tenantens tidssone, uavhengig av valgt analyseperiode. Prompten beskriver valgt sammenligningsmodus og begge faktiske datovinduer.

Konkurrentfilteret velger trendens sammenligningsserier, med egen merkevare beholdt, ikke et nytt utvalg av råsvar. Kilde- og trafikkvisninger tilbyr bare dimensjoner datakildene støtter. Dette er presiseringer av U11, ikke nye filtreringsmetrikker.

`saved_views` eies av tenant og bruker, og er avgrenset til merkevare og analyseside. Tabellen har FORCE RLS på tenant og en sammensatt tenant/brand-fremmednøkkel. Listen og sletting avgrenser også på innlogget bruker. Alle som kan lese merkevaren kan lagre egne visninger; det gir ingen rett til å endre merkevaren. Navn brukes som upsert-nøkkel innen eget omfang.

URL-er inneholder valg, aldri en privat visnings-ID som mottakeren må ha tilgang til. Brukere kan derfor dele filtrene uten å dele de lagrede visningene. Relative perioder fortsetter å være relative; eksplisitte datoer forblir faste.

## Konsekvenser

Filterendringer henter også deferred-props, slik at gamle grafer ikke vises som nye resultater. Full navigasjon mellom analysesider er fortsatt vanlig Inertia-navigasjon. Historiske innsiktstekster med gammel cacheidentitet blir ikke gjenbrukt for et annet vindu. Ingen eksterne modellkall gjøres i nettlesertestene.
