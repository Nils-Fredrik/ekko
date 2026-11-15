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
- Etter oppstart eier deployløpet i T10 image, webtrafikk og revisjonsnavn. Terraform ignorerer disse feltene og deployklientens metadata. T10 må holde produksjonstrafikken på en eksplisitt testet revisjon ved infrastrukturendringer som lager nye revisjoner. Startkoden for worker (T17) begrenser `taskCount` til maks åtte, med automatisk parallellisme i Cloud Run.

## Konsekvenser

Første oppstart og passordrotasjon krever operatørarbeid, og databaserollene kan ikke fullt ut kontrolleres for drift med `terraform plan`. Det må inngå eksplisitte sikkerhetskontroller av roller, grants og brukertilganger før produksjon. Etter en godkjent apply må en ny plan bekrefte at Terraform-administrerte ressurser er uten endringer; dette beviser ikke at manuelle databasebrukere er riktige.
