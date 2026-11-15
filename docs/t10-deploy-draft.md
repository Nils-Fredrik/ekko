# T10 – gjenstående produksjonsvalidering

PR #31 er fortsatt et utkast. CI bygger web- og jobbimage, kjører alle kodekontroller og Chromium-testene for innlogging og dashboard. `ci.yml` inneholder nå deploy-jobben, men produksjonskjøring er eksplisitt sperret til repository-variabelen `EKKO_DEPLOY_ENABLED=true` settes etter bootstrap og akseptbevis. Variabelen er ikke satt. Pågående main-kjøringer kanselleres ikke av nye push. Avvik og gjenstående akseptkriterier står i spec 17.2 og på Board task:35.

## Røyktestkontrakt

`scripts/smoke.sh URL` kjører Playwright/Chromium mot kandidatens opprinnelse. Den krever:

- `SMOKE_EMAIL` og `SMOKE_PASSWORD`: en verifisert, syntetisk lesebruker i en egen testtenant, uten partner- eller plattformadminrettigheter.
- `SMOKE_TENANT_ID` og `SMOKE_BRAND_ID`: valgt tenant og merkevare med syntetiske data som skal finnes i dashboardet.
- `SMOKE_REST_TOKEN`: et personlig, tenantbundet token med bare `read`, opprettet etter T33.
- `SMOKE_MCP_CLIENT_ID`: en bootstrap-registrert offentlig MCP-klient med callback `http://127.0.0.1:8765/callback`.

Brukernavn/passord/token hentes fra CI-hemmeligheter, aldri argumenter eller repo. Testen logger bare bestått eller navnet på feilet trinn. Ingen trace, skjermbilder, cookie-filer eller responsinnhold lagres. Nettleseren blokkerer andre opprinnelser. REST/MCP bruker separat HTTP-kontekst uten innloggingscookies og følger ikke redirects.

Testen logger inn gjennom den ekte Vue-siden, kontrollerer synlig merkevare og Inertia-data i dashboardet, henter REST-metrikker og krever 401 uten token. Den gjennomfører OAuth authorization code med PKCE og lesesamtykke for testtenanten, initialize, whoami og tools/list. Koden sendes aldri til callback-adressen. MCP-tilkoblingen tilbakekalles i finally. Dersom prosessen termineres før opprydding, må operatøren tilbakekalle testtilkoblingen på `/claude`; access-token varer én time.

Installer Node-avhengigheter og Chromium før kjøring (`npm ci`, `npx playwright install --with-deps chromium`). Manglende konfigurasjon, redirect, HTTP-feil, feil tenant, ugyldig kontrakt eller feil ved tilbakekall stopper utrulling.

## Før aktivering

1. T33 og T34 er integrert i main etter PR #38 og #36; T26 er også integrert. Legg til veivisertest etter T25/T29.
2. Verifiser begge container-targets og runtime-kommandoer. Bootstrap SQL-brukere, secret-versjoner, første images og Cloud Run etter Terraform-runbooken. Bekreft at gammel webtrafikk er låst til en konkret revisjon, aldri LATEST.
3. Verifiser IAM/WIF i isolert miljø. Betingelsen er låst til `.github/workflows/ci.yml` på `main` og push. Sett `GCP_WORKLOAD_IDENTITY_PROVIDER` fra verifisert Terraform-output.
4. Opprett testtenant, lesebruker, token og MCP-klient. Legg konfigurasjon i GitHub-hemmeligheter/variabler. Kjør full smoke på kjent god kandidat.
5. Rull ut en bevisst feilende revisjon uten trafikk i isolert Cloud Run. Dokumenter før/etter-fordeling av trafikk, revisjons-ID og feilet smoke. Test også feil ved jobboppdatering etter webpromotering og prosedyren for tilbakeføring.
6. Sett `EKKO_DEPLOY_ENABLED=true` først når bevisene foreligger. Eieren merger etter godkjent kryssreview og grønn CI.

`tests/deploy/test-deploy.sh` tester kommandoenes rekkefølge og feilveier med mocks. Det er ikke bevis på Cloud Run-oppførsel. Ingen produksjonsressurser eller trafikk er endret i dette arbeidet. Se `docs/runbook.md` for tilbakeføring.
