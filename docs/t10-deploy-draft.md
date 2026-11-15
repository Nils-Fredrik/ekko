# T10 – gjenstående produksjonsvalidering

PR #31 er fortsatt et utkast. CI bygger web- og jobbimage, kjører alle kodekontroller og Chromium-testene for innlogging og dashboard. `ci.yml` inneholder nå deploy-jobben, men produksjonskjøring er eksplisitt sperret til repository-variabelen `EKKO_DEPLOY_ENABLED=true` settes etter bootstrap og akseptbevis. Variabelen er ikke satt. Pågående main-kjøringer kanselleres ikke av nye push. Avvik og gjenstående akseptkriterier står i spec 17.2 og på Board task:35.

## Røyktestkontrakt

Container-jobben kontrollerer både web- og jobbimaget, lagrer dem sammen i et komprimert artifact med én dags levetid, laster arkivet ned igjen og kontrollerer identitet og runtime-innhold. Deploy henter nøyaktig dette artifact-ID-et fra samme workflow-kjøring, kontrollerer sjekksummen og publiserer imagene uten nytt bygg. Produksjonsutrulling bruker registry-digestene. PR-kjøringer publiserer aldri til Artifact Registry.

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
4. Opprett testtenant, lesebruker, token og MCP-klient. Legg konfigurasjon i GitHub-hemmeligheter/variabler. Verifiser klient-IP og beskyttelse mot forfalsket `X-Forwarded-For` på kandidaten før webtrafikk åpnes (ADR 0007); dagens IP-baserte rategrenser kan deles mellom brukere bak proxyen. Kjør full smoke på kjent god kandidat.
5. Rull ut en bevisst feilende revisjon uten trafikk i isolert Cloud Run. Dokumenter før/etter-fordeling av trafikk, revisjons-ID og feilet smoke. Test også feil ved jobboppdatering etter webpromotering og prosedyren for tilbakeføring.
6. Sett `EKKO_DEPLOY_ENABLED=true` først når bevisene foreligger. Eieren merger etter godkjent kryssreview og grønn CI.

`tests/deploy/test-deploy.sh` tester kommandoenes rekkefølge og feilveier med mocks. Det er ikke bevis på Cloud Run-oppførsel. Se `docs/runbook.md` for tilbakeføring.

## Kontrollert oppstartsstatus 15. november 2026

GCP-tilgang og Terraform-backend er verifisert. SQL-instansen og registry finnes. En gjennomgått, målrettet IAM/WIF-plan opprettet 13 ressurser uten endring eller sletting av eksisterende ressurser: GitHub-pool/provider, STS og avgrensede CI-rettigheter, samt leserollen for worker-kjøringer. Målrettingen utelot en uvedkommende Cloud SQL-planendring uten synlig før/etter-differanse; hele infrastrukturen er derfor ikke erklært driftfri.

`ekko_app` og `ekko_migrate` er opprettet utenfor Terraform. Begge direkte innlogginger er verifisert. Approllen har ingen superbruker-, rolle-, database-, replikerings- eller BYPASSRLS-rettigheter og ingen rollemedlemskap. Migreringsrollen har BYPASSRLS, rett til å opprette objekter i public og medlemskap i approllen for SET ROLE, men ingen superbruker-, CREATEROLE- eller CREATEDB-rettigheter. En syntetisk tabell med FORCE ROW LEVEL SECURITY ga null rader uten tenant, én rad for hver valgt tenant og to for migreringsrollen. Tabellen ble rullet tilbake, og midlertidige operatører slettet.

Appnøkkel og begge databasepassord er generert og lagt direkte i Secret Manager, uten verdier i argumenter, logger eller Terraform-state. De sju eksterne API-/OAuth-hemmelighetene er fortsatt tomme. Ingen Cloud Run-tjenester eller webtrafikk er opprettet. Øvrige hemmeligheter og workload-bootstrap gjenstår før produksjonsaksepten. Terraform-etterkontrollen av fundamentet viser ingen nye ressurser eller slettinger; bare den uendrede Cloud SQL-differansen gjenstår. GitHub-variabelen `GCP_WORKLOAD_IDENTITY_PROVIDER` er satt fra verifisert Terraform-output.

Første web-/jobbimages er publisert direkte fra det godkjente artifactet i [CI-kjøring 43746900173](https://github.com/Nils-Fredrik/ekko/actions/runs/43746900173), kildecommit `a7020a7`, uten nytt bygg. Web-digest er `sha256:f54c22e1844cdff7a6d9fabb04c81340567f7893e549c37afabd6345d3dbce38`; jobs-digest er `sha256:82fb7d52a9449463d7341d69fe90002beba65347510b7d2a63640ff83de73859`. Begge ligger i prosjektets `ekko`-registry under henholdsvis `web` og `jobs`. Docker beholdt en lokal RepoDigest før registry-digesten; deploy velger derfor nøyaktig repository med `scripts/image-digest.py`, og avviser manglende eller tvetydig treff.

On-demand-backup `1794767625561` er bekreftet SUCCESSFUL. Det samme jobbimaget kjørte de 28 migreringene som allerede var på main mot den tomme Cloud SQL-databasen. `migrate:status` bekreftet alle som Ran. En workload-plan med de konkrete digestene, privat web og pausede schedules viser 25 opprettelser, én Cloud SQL no-op-endring og ingen slettinger. Planen er ikke anvendt fordi eksterne hemmelighetsversjoner mangler. Dette erstatter ikke live aksept av trafikkpromotering og tilbakeføring.

Et senere review avdekket HTTP-genererte lenker bak Cloud Runs TLS-terminering. Rettelsen i `bootstrap/app.php` stoler bare på videresendt protokoll; tester dekker HTTPS i OAuth-metadata, login/asset-lenker, avvisning av forwarded host/port og uendret lokal HTTP. De første image-digestene over dokumenterer migreringsbeviset; workload-start må bruke et nytt, grønt CI-image som inkluderer HTTPS-rettelsen.

Nye images med HTTPS-rettelsen er publisert uten nytt bygg fra det verifiserte artifactet i [CI-kjøring 43748764084](https://github.com/Nils-Fredrik/ekko/actions/runs/43748764084), kildecommit `6a5ea9c` (artifact-ID `14175288035`). Sjekksummen er kontrollert før innlasting. Web-digest er `sha256:e9f259b2122dfbfe1de398a4e370b256634d7ebb648c8af8f54e2eb8b965a6ea`; jobs-digest er `sha256:836fc395354e02e3c2da7d9ac4a7b42cca0b93e3746a1adc35898c38ab08cd65`. HTTPS-konfigurasjonen er også kontrollert i webcontaineren. Ingen workloads eller trafikk er startet. En ny workload-plan må bruke disse eller nyere verifiserte digester.

## Fortsettelse 15. november: produksjonsdomene og direkte API-er

Den tidligere statusen over er historikk. Grenen ble hentet på `1fc6cde` etter konfliktløsningen; [CI 43761087794](https://github.com/Nils-Fredrik/ekko/actions/runs/43761087794) er grønn. PR-en forblir utkast og de åpne reviewtrådene om live produksjonsaksept består.

Alle tre direkte leverandørnøkler (OpenAI, Anthropic, Gemini) har nå aktiv versjon 1. Google OAuth-hemmeligheten har ingen versjoner. Microsoft, Perplexity og DataForSEO er ikke oppstartskrav. Runtime-oppsettet er tilpasset dette, mens eksisterende beholdere beholdes. OpenAI Responses med EU-endepunkt, `store=false`, snapshot `gpt-5-mini-2025-08-07` og påkrevd websøk ga HTTP 200 / completed og ett søk med et syntetisk spørsmål. Ingen nøkkel eller svartekst ble logget. Gemini-grounding er fortsatt et eksplisitt avklaringspunkt (ADR 0024).

Avgrenset HTTPS-bootstrap opprettet sju ressurser: to API-aktiveringer, global IPv4, DNS-autorisasjon, sertifikat, sertifikatkart og kartoppføring. Planen hadde ingen endringer eller slettinger. Ingen Cloud Run-tjeneste eller trafikk er startet. Dette er et målrettet bootstrapbevis, ikke en ren full Terraform-plan.

Publiser disse postene hos dagens DNS-leverandør, TTL 300:

| Navn | Type | Verdi |
|---|---|---|
| `ekko.maksimer.com.` | A | `35.190.63.68` |
| `_acme-challenge_ekbcfyjihdz4tixc.ekko.maksimer.com.` | CNAME | `f6002ce0-9805-451e-8b30-eece34a2f205.15.authorize.certificatemanager.goog.` |

Sertifikatet venter på DNS. Lastbalansererens backend og forwarding rules kommer ved workload-bootstrap. Lokal, ignorert `production.auto.tfvars` bevarer `enable_https=true`; nye arbeidskopier må bruke eksempelfilen. Google OAuth callback er `https://ekko.maksimer.com/auth/google/callback`. Eierens Web OAuth-klient og hemmelighetsversjon gjenstår før workloads kan opprettes. Se Terraform-README for detaljene, uten å dele hemmeligheter i chatten.

Før schedules aktiveres må produksjonsmotorer/pakker seedes, eksisterende oppsett gjennomgås og godkjente motorpriser registreres. Gemini må avklares særskilt. Før offentlig trafikk må klient-IP-kjeden verifiseres via både ny lastbalanserer og direkte Cloud Run. Dette må samordnes med T39-F3 / PR #55; dagens lokale tester beviser ikke den virkelige proxykjeden.

Full etterplan for fundament/HTTPS viser tre planlagte IAM-fjerninger (Microsoft på web, DataForSEO og Perplexity på worker), én tidligere Cloud SQL no-op og ingen opprettelser. SQLs før-/etter-verdier er identiske; differansen gjelder sensitivitetsmetadata. Planen er ikke anvendt. En separat workload-plan med syntetisk OAuth-klient-ID (kun plan, aldri apply) validerte 34 opprettelser og kontraktene for kanonisk URL, callback, direkte secrets, Gemini-sperre, privat web, pausede schedules, TLS 1.2 og deaktivert CDN. Den syntetiske planfilen ble slettet etter kontroll. Ny plan med ekte klient-ID og nye CI-digester kreves før oppstart.

Lokalt består `composer test` med 1635 tester / 9382 assertions (512 MB PHP-minne), inkludert tenant-isolasjon. `composer lint` (Pint/Larastan), `npm run lint` (fire eksisterende Vue-advarsler), `npm run build`, deploy-mocktester, fire digest-tester og Terraform fmt/validate består. Første fulltest krevde nytt frontend-bygg etter oppdateringen fra main; ingen tester ble svekket. OpenAI modellisteverifisering mot `eu.api.openai.com/v1/models` ga også HTTP 200 med gyldig liste.

`npm run test:browser` består med tre Chromium-tester, inkludert autentisert login/REST/OAuth/MCP-smoke og tilbakekalling. Dette er fortsatt lokalt bevis, ikke Cloud Run-produksjonsaksept.


[CI 43762928988](https://github.com/Nils-Fredrik/ekko/actions/runs/43762928988) er grønn for `8486e18` (test og containerbygg). Claude fant ingen blokkerende kodefeil; Codex fant ingen vesentlige feil. Claude opprettholder kravet om produksjonsaksept. Småmerknadene om `trim(null)` og mellomrom i leverandørlisten er rettet i oppfølgingscommit. Gemini-aktiveringens to steg og gjennomgang av eksisterende pakker står allerede i Terraform-README. Klient-IP må fortsatt verifiseres for begge innkommende veier; ikke bruk et fast antall videresendte ledd.
