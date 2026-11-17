# 0007 – Terraform, databasebrukere og deployansvar

- **Status:** Akseptert for implementering; produksjonsoppsett må verifiseres før lansering
- **Dato:** 2026-11-14
- **Board:** `task:34` / T09

## Kontekst

T09 krever GCP-ressursene i spesifikasjonen, minste privilegium og at passord og nøkler ikke lagres i Terraform-state. ADR 0003 forutsetter at `ekko_app` finnes før migreringen, og nevner Terraform som oppretter. Cloud SQLs innebygde brukere får normalt rollen `cloudsqlsuperuser` hvis de opprettes uten eksplisitt begrensning. Terraform 1.5, som er tilgjengelig i utviklingsmiljøet, støtter ikke write-only-argumentene som hindrer at passord havner i state.

## Beslutning

- Terraform lager Cloud SQL-instans, database, hemmelighetsbeholdere og IAM, men oppretter ikke SQL-innloggingene eller hemmelighetsversjonene. En operatør oppretter dem utenfor Terraform. Dette avviker fra formuleringen i ADR 0003; den øvrige RLS-modellen gjelder fortsatt.
- Før første migrering skal operatøren verifisere at `ekko_app` kan logge inn, mangler `BYPASSRLS`, `CREATEROLE`, `CREATEDB`, superbrukerrettigheter og privilegerte rollemedlemskap. Brukeren må ikke arve `cloudsqlsuperuser`. Migreringsrollen skal være separat og ha privilegiene spesifikasjonen krever. Verifisering av hvordan `BYPASSRLS` gis i den faktiske Cloud SQL-instansen er en sperre for produksjonssetting, ikke en antakelse om at det virker.
- Terraform oppretter ikke hemmelighetsverdier og leser dem ikke inn i state eller plan. Drift oppretter og roterer hemmelighetsversjoner og databasepassord med en sikker prosess. Presisert etter T39-F1 (`task:50`, 15.11.2026): web får bare den vanlige RLS-innloggingen. Migreringspassordet og tilgangen til det tilhører kun `ekko-migrate`. Dagens plattformadmin-handlinger bruker ikke `pgsql_admin`; et senere behov for BYPASSRLS krever en separat rolle uten eierskap/DDL og en eksplisitt, logget kjørevei, ikke gjenbruk av migreringslegitimasjonen.
- Cloud Run og Scheduler opprettes først når et faktisk image og nødvendige hemmelighetsversjoner er tilgjengelige. Web starter privat og åpnes først etter verifisering. Scheduler er pauset inntil kommandoer og validering av OIDC-token på interne endepunkter er implementert og kontrollert. En offentlig Cloud Run-tjeneste beskytter ikke individuelle URL-er med IAM.
- Etter oppstart eier deployløpet i T10 image og webtrafikk. Terraform ignorerer disse feltene og deployklientens metadata. **Presisering etter live-test 16.11.2026:** `template.revision` skal ikke ignoreres; da forsøker Terraform å gjenbruke et uforanderlig CLI-navn ved konfigurasjonsendring, og Cloud Run svarer 409. Terraform lar revisjonsnavnet være usatt slik at API-et genererer det. Etter en navngitt deploy kan planen derfor nullstille navnet. T10 må holde produksjonstrafikken på en eksplisitt testet revisjon ved infrastrukturendringer som lager nye revisjoner. Startkoden for worker (T17) begrenser `taskCount` til maks åtte, med automatisk parallellisme i Cloud Run.
- Cloud Run terminerer TLS før Apache. Laravel stoler derfor på `X-Forwarded-Proto` fra betrodde proxyer for å generere HTTPS-lenker, assets og OAuth-metadata. Forwarded host og port stoles ikke på; de kan ikke flytte lenker til en annen opprinnelse. Direkte HTTP uten headeren fungerer fortsatt lokalt. Containeren skal bare eksponeres gjennom Cloud Runs frontend i produksjon. Etter T39-F3 og T10-oppfølgingen 16.11.2026 stoles også `X-Forwarded-For` på gjennom den eksplisitte proxylisten: faktisk tilkoblingsadresse (`REMOTE_ADDR`) og den faste lastbalanserer-IP-en. Før webtrafikken åpnes må klientidentitet og forfalskning verifiseres både direkte og via lastbalansereren. Regresjonstester dekker begge kjeder; live-status står i T10-statusdokumentet og ADR 0024.

## Konsekvenser

Første oppstart og passordrotasjon krever operatørarbeid, og databaserollene kan ikke fullt ut kontrolleres for drift med `terraform plan`. Det må inngå eksplisitte sikkerhetskontroller av roller, grants og brukertilganger før produksjon. Etter en godkjent apply må en ny plan bekrefte at Terraform-administrerte ressurser er uten endringer; dette beviser ikke at manuelle databasebrukere er riktige.
