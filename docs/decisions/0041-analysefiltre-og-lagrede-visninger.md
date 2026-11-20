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

Daglige grafpunkter ved årssammenligning kobles på kalenderdato med samme 29. februar-avkorting som datovinduet; mars forskyves ikke av skuddårsdagen. Månedene kobles på kalendermåned. Ferdig aggregerte ukebøtter beholder rekkefølgen innen hvert datovindu, siden mandagsgrensene er ulike mellom år og deluker ellers ville blitt duplisert.

Gruppering velges og lagres bare på merkevareoversikten, som viser tidsserier. Fakta- og tiltakslenker tar ikke med analysefiltre. Fremtidige datoer er tillatt og gir tomme målinger; de endrer ikke innsiktskvotens faktiske tenant-dag. Stabilitetsprikkene på promptlisten beholder sitt særskilte 14-dagersvindu fram til valgt sluttdato, også når den valgte hovedperioden er kortere.

## Konsekvenser

Merkevaredashboardets databaseavhengige props evalueres først etter at Inertia har valgt hvilke props svaret trenger (U11-F1, task:115). Nøkkeltall, dagserie, stemmeandel og motorer deler én lokalt mellomlagret dashboardberegning per svar. Dette er ikke en ny cache mellom forespørsler: neste svar leser ferske tall. Innsikt beholder sin egen beregning av gjeldende og tidligere periode (ADR 0038); grafene beholder eksisterende GraphCache. Autorisasjon og filtervalidering kjøres også ved delvise forespørsler.

Filterendringer henter utsatte grafdata i samme delvise svar. Innsiktsteksten hentes i en separat forespørsel etter at grafene er oppdatert, slik at modellkall ikke forsinker tallene (ADR 0038). Tidligere innsiktstekst tømmes og gamle forespørsler avbrytes ved neste filterskifte. Full navigasjon mellom analysesider er fortsatt vanlig Inertia-navigasjon. Historiske innsiktstekster med gammel cacheidentitet blir ikke gjenbrukt for et annet vindu. Ingen eksterne modellkall gjøres i nettlesertestene.
