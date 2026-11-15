# ADR 0024: Produksjonsdomene og direkte motorer

Dato: 2026-11-15. Status: besluttet av eieren i T10 / PR #31, Board task:35.

## Bakgrunn og beslutning

Eieren velger `https://ekko.maksimer.com` og direkte Anthropic, OpenAI og Gemini ved oppstart. Perplexity og DataForSEO utsettes. Dette erstatter standardvalgene i spec 3.1, 7.4 og 12.1; de øvrige adapterne beholdes.

Google [anbefaler en global ekstern Application Load Balancer](https://docs.cloud.google.com/run/docs/mapping-custom-domains) for produksjonsdomener; Cloud Run domain mapping er fortsatt preview og ikke anbefalt for produksjon. Vi bruker serverless NEG til `ekko-web`, Certificate Manager med DNS-autorisasjon, statisk IPv4, TLS 1.2 eller nyere og HTTP-omdirigering til den faste HTTPS-verten. CDN er av. IP og sertifikatgrunnlag opprettes før workloads, slik at DNS-autorisasjon kan fullføres mens OAuth klargjøres. Sertifikatets CNAME beholdes for fornyelse. DNS-sonen blir hos dagens leverandør.

Cloud Run beholder `INGRESS_TRAFFIC_ALL` for eksisterende kandidatadresser og Scheduler. Dette betyr at `run.app` også er tilgjengelig når public IAM aktiveres; lastbalansereren er ingen egen tilgangsgrense. Eventuell senere Cloud Armor/bypass-sperre krever at kandidat-røyktest og Scheduler tilpasses. Klient-IP-kjeden må testes både direkte og via lastbalansereren før offentlig åpning. `TRUSTED_PROXIES=*` alene fra T39-F3 er ikke automatisk riktig for den nye kjeden. Host/Port skal fortsatt ikke stoles på fra klienten.

Lastbalanserer, ekstern IPv4 og databehandling gir løpende kostnader i tillegg til det gamle anslaget i spec 17.3. Ingen kostnadsfri domenetilknytning er forutsatt.

## OpenAI og nøkler

OpenAI-innsamling og BYOK-verifisering bruker `https://eu.api.openai.com/v1`, uten global fallback eller redirects. Standardmodellen er det eksplisitte snapshotet `gpt-5-mini-2025-08-07`, og `store=false` beholdes. Plattformnøkkel brukes når ingen aktiv BYOK-nøkkel er valgt. BYOK-nøkler må tilhøre et Europe-prosjekt; vi forsøker ikke et annet endepunkt når verifisering feiler.

[OpenAIs regiondokumentasjon](https://developers.openai.com/api/docs/guides/data-residency) oppgir støtte for dette snapshotet, Responses og Web Search i Europa. Regionvalget gjelder støttet kundeinnhold, ikke systemdata eller tredjepartstjenester; det er ikke et løfte om at enhver søkeleverandør behandler data i EU. Nyere modeller kan ha regionalt pristillegg og må kontrolleres før modellbytte. Et syntetisk Responses-kall mot EU med påkrevd websøk ga HTTP 200, `completed`, forventet snapshot og ett søk. Ingen nøkkel eller svartekst ble skrevet ut eller lagret.

## Gemini og utsatte leverandører

Gemini støtter plattformnøkkel med samme BYOK-/budsjettsemantikk. [Googles vilkår](https://ai.google.dev/gemini-api/terms#grounding-with-google-search) begrenser analyse, lagring og automatisk innsamling av grounding-resultater. Den tidligere avklaringen i spec 7.4 oppheves derfor ikke av motorvalget: `gemini_grounding_approved=false` stopper produksjonskall, også med BYOK, til Ekkos konkrete bruk er avklart. Betalt Gemini-prosjekt kreves for brukere i EØS; tjenesten gir ikke et generelt løfte om EU-lagring/behandling.

Perplexity og DataForSEO fjernes fra runtime-hemmeligheter og IAM-lesetilgang. Beholderne bevares uten nye versjoner. En produksjonssperre stopper planlegging, innsamling og polling, mens historikk fortsatt kan leses. Produksjonsseederen oppretter direkte motorer uten demoidentiteter eller demopriser. Eksisterende pakker, priser og markedskonfigurasjoner må gjennomgås før schedules åpnes; seeding skal ikke overskrive operatørens valg.

## Google OAuth

OAuth-klienten opprettes som Web application utenfor Terraform, med callback `https://ekko.maksimer.com/auth/google/callback`. Terraform tar bare klient-ID og referansen til `ekko-google-oauth-client-secret`; hemmelighetsverdien og versjoner skal aldri inn i state. Microsoft er utsatt og kreves ikke lenger for workload-bootstrap. Invitasjons- og tenantreglene fra T07 gjelder uendret.
