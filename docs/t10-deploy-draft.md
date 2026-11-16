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

Under privat bootstrap kan operatøren gi `SMOKE_CLOUD_RUN_ID_TOKEN` som et kortlevd Google ID-token i prosessmiljøet. Testen sender det i `X-Serverless-Authorization`, slik at REST/MCP beholder sin egen `Authorization`. Dette krever HTTPS, følger samme opprinnelsesgrense og logger aldri tokenet. Vanlig offentlig deploy trenger ikke variabelen. Se [Googles autentiseringsdokumentasjon](https://docs.cloud.google.com/run/docs/authenticating/service-to-service).

Testen logger inn gjennom den ekte Vue-siden, kontrollerer synlig merkevare og Inertia-data i dashboardet, henter REST-metrikker og krever 401 uten token. Den gjennomfører OAuth authorization code med PKCE og lesesamtykke for testtenanten, initialize, whoami og tools/list. Koden sendes aldri til callback-adressen. MCP-tilkoblingen tilbakekalles i finally. Dersom prosessen termineres før opprydding, må operatøren tilbakekalle testtilkoblingen på `/claude`; access-token varer én time.

Installer Node-avhengigheter og Chromium før kjøring (`npm ci`, `npx playwright install --with-deps chromium`). Manglende konfigurasjon, redirect, HTTP-feil, feil tenant, ugyldig kontrakt eller feil ved tilbakekall stopper utrulling.

## Før aktivering

1. T33 og T34 er integrert i main etter PR #38 og #36; T26 er også integrert. Legg til veivisertest etter T25/T29.
2. Verifiser begge container-targets og runtime-kommandoer. Bootstrap SQL-brukere, secret-versjoner, første images og Cloud Run etter Terraform-runbooken. Bekreft at gammel webtrafikk er låst til en konkret revisjon, aldri LATEST.
3. Verifiser IAM/WIF i isolert miljø. Betingelsen er låst til `.github/workflows/ci.yml` på `main` og push. Sett `GCP_WORKLOAD_IDENTITY_PROVIDER` fra verifisert Terraform-output.
4. Opprett testtenant, lesebruker, token og MCP-klient. Legg konfigurasjon i GitHub-hemmeligheter/variabler. Verifiser klient-IP og beskyttelse mot forfalsket `X-Forwarded-For` på kandidaten før webtrafikk åpnes (ADR 0007). Kontrollene for dagens proxykjede er dokumentert nedenfor; gjenta dem ved endring. Kjør full smoke på kjent god kandidat.
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

Publiser disse postene hos dagens DNS-leverandør, TTL 300. I Cloudflare skal **begge** være **DNS only** (grå sky); sertifikatposten må svare med den eksakte CNAME-en, og appens A-post må svare med Google-IP-en:

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

## Fortsettelse 16. november: privat oppstart og ekte røyktest

Eierens Google Web OAuth-fil ble validert lokalt mot prosjektet og den eksakte callbacken. Client secret ble overført direkte til Secret Manager som aktiv versjon 1; klient-ID ligger i ignorert Terraform-konfigurasjon. Ingen hemmelighetsverdi er lagt i Terraform, chat, Git eller Board. DNS er kontrollert som DNS only, og sertifikat og sertifikatkartoppføring er ACTIVE.

Web- og jobbimage fra [CI 43763898222](https://github.com/Nils-Fredrik/ekko/actions/runs/43763898222), commit `337188a`, artifact `14181665621`, er publisert uten nytt bygg etter kontroll av arkivsjekksum og begge containernes innhold:

- Web: `europe-west1-docker.pkg.dev/ekko-514612/ekko/web@sha256:27f0770c2a11720f3be4e24b861c994a644e0583dc2a1246c44efffc7ddec22d`.
- Jobs: `europe-west1-docker.pkg.dev/ekko-514612/ekko/jobs@sha256:acf6f2342661bc8fa88943cae5ce315e309c006247ed9f879ac197e24ecec921`.

Gjennomgått full Terraform-apply opprettet 34 ressurser og fjernet tre ubrukte IAM-tilganger (Microsoft på web, DataForSEO/Perplexity på worker). SQL-endringen hadde identiske konfigurasjonsverdier før/etter. Cloud Run opprettet automatisk en skaleringsblokk på tjenesten; eksplisitt `scaling.min_instance_count = 0` gjør koden lik faktisk tilstand. Ny full plan gir **No changes** (exitkode 0). Én tidligere etterkontroll ble gjentatt etter en nettverksfeil ved IAM-lesing.

Web er privat, og alle åtte Scheduler-jobber er PAUSED. Backup `1794815852453` er SUCCESSFUL. Cloud Run-kjøringen `ekko-migrate-fg8dv` fullførte migreringen med det nye jobbimaget. Produksjonsseeding opprettet standardpakker og direkte motorer uten eksempelpriser. En egen syntetisk tenant har null innsamlingskvote, en lesebruker uten admin-/eierrettigheter, en syntetisk merkevare, et tenantbundet read-token og en MCP-klient med loopback-callback. Brukerens innlogging og REST-token er lagret som GitHub Actions-hemmeligheter; tenant-/brand-/client-ID er repository-variabler. `EKKO_DEPLOY_ENABLED` er fortsatt ikke satt.

Ekte Chromium-røyktest bestod gjennom både `https://ekko-web-rdj2oacpcq-ew.a.run.app` og `https://ekko.maksimer.com`: innlogging, rendret dashboard, REST-metrikker, avvisning uten app-token, OAuth/PKCE, MCP initialize/whoami/tools/list og tilbakekalling av tilkoblingen. Privat transport brukte Google ID-token i `X-Serverless-Authorization`; alle eksisterende app-kontroller ble beholdt. Først ble kun transportheaderen lagt til i en midlertidig kopi; dette støttes nå direkte i `scripts/smoke.mjs`.

HTTP-kontroller på begge adresser bekreftet IAM 403 uten Google-token, helse/login 200 med token, sikre `__Host-`-sesjonscookies, korrekt Google-redirect med identitetsscopes, REST 401 uten app-token og intern Scheduler-rute 401 uten Scheduler OIDC. HTTP på domenet omdirigerer til HTTPS. Sertifikatet ble tilgjengelig på lastbalansereren noen minutter etter opprettelse. Trafikken ble deretter låst til den testede revisjonen `ekko-web-00001-qpq`, uten LATEST.

Dette beviser appens private oppstart og den syntetiske innloggingsflyten, ikke ekte Google-innlogging med invitert/uinvitert konto. Klient-IP/spoofing via begge innkommende veier (T39-F3/#55), godkjente priser og Gemini-vilkårsavklaring gjenstår før offentlig åpning og innsamling. Board CLI 1.1.0 er testet med lesing/hendelser og posting som Codex; CLI erstatter Board-MCP etter eierens instruks.

### Feilende kandidat og delvis jobboppdatering

`deploy.sh` ble kjørt mot ekte Cloud Run med Google IAM-headeren fra miljøet. En lokal feilinjeksjon endret bare `DB_HOST` i `gcloud run deploy` til en syntetisk, ikke-eksisterende socket. Backup og migreringsjobb fullførte. Revisjon `ekko-web-t10-negative-20261116` ble Ready med 0 % trafikk; røyktesten feilet på login og skriptet stoppet med exitkode 1. `ekko-web-00001-qpq` hadde 100 % både før og etter. Ingen applikasjonsjobber ble oppdatert.

Deretter ble de tre pausede applikasjonsjobbene satt til det tidligere verifiserte `6a5ea9c`-imaget som kontrollert utgangspunkt; ingen av dem ble kjørt. Neste deploy opprettet `ekko-web-t10-partial-20261116` med korrekt DB-host. Full smoke bestod, web ble promotert til 100 %, og dispatch ble oppdatert. Feilinjeksjonen erstattet kun image-argumentet ved worker-oppdateringen med en ikke-eksisterende digest. Cloud Run avviste denne med `Image not found`, og deploy stoppet med exitkode 1 før task-oppdateringen.

Etterkontroll viste web på den nye revisjonen, dispatch på nytt image, task på tidligere image og worker med den ugyldige referansen i lagret konfigurasjon. `rollback-traffic.sh ekko-web-00001-qpq` tilbakeførte web til 100 %. Alle tre applikasjonsjobber ble eksplisitt gjenopprettet til `337188a`-digesten. Runbooken beskriver nå at en feilet jobboppdatering også kan kreve reparasjon. Dette var virkelig Cloud Run-oppførsel med kontrollert feilinjeksjon, ikke mocks; offentlig tilgang og schedules var av hele tiden.

Lokale kontroller etter endringene: 1635 PHP-tester / 9382 assertions, Pint/Larastan, frontend-lint, fire Chromium-tester og deploy-mocktester består. Den nye Chromium-testen avviser IAM-token over HTTP uten å skrive tokenet i output.

### Oppdatert main, proxykjede og Terraform-revisjoner

Main ble oppdatert til `dcafa7e` med #55 og #62. Mergetesten beholdt én proxykonfigurasjon og satte eksplisitt testopprinnelse i host-regresjonen, slik at lokal `APP_URL=127.0.0.1` ikke påvirker forventningen. [CI 43835532299](https://github.com/Nils-Fredrik/ekko/actions/runs/43835532299) er grønn på `6a4c048`; Claude og Codex fant ingen blokkerende kodefeil. Claude godtar de konkrete negative-/rollback-testene som oppfylt, men beholder produksjonskravene.

Artifact `14210393755` ble sjekksumkontrollert, begge runtime-kontekster kontrollert og imagene publisert uten nytt bygg. Oppdatert web bruker `sha256:c26c5ed7241fdc2b1a9be76b935f08b462a705478ee0b1ed57c01986195c2791`; alle fire jobber bruker `sha256:62cbf6e502505e5933a7c92721c9238997cdd157e6175af2aa6eae5147431a74`, i samme registry som over. Full smoke bestod på kandidat `ekko-web-t10-ip-6a4c048` før privat promotering.

En ekte infrastrukturendring avdekket at `ignore_changes` på `template.revision` forsøkte å gjenbruke CLI-revisjonen og ga HTTP 409. Denne ene ignore-regelen er fjernet; image og trafikk håndteres fortsatt av deploy. API-et får generere revisjonsnavnet når Terraform oppdaterer konfigurasjon. Anvendt rettelse ga ny revisjon uten trafikkflytting og deretter **No changes**. Etter en navngitt CLI-deploy kan neste plan nullstille navnefeltet; vurder dette med øvrige infrastrukturendringer. Se [upstream-feilen](https://github.com/hashicorp/terraform-provider-google/issues/17218) og ADR 0007.

Live-test med fire syntetiske innlogginger bekreftet at `TRUSTED_PROXIES=*` ignorerer forfalskning, men gir ALB-IP-en som klient på domenet. Terraform setter nå `REMOTE_ADDR,35.190.63.68`; støtten for plassholderen er kontrollert i installert Laravel/Symfony. To nye regresjoner dekker varierende falske klient- og ALB-ledd, samme klientgrense og separat grense for en annen klient. Anvendt proxyendring opprettet `ekko-web-00006-4jh`. Full smoke bestod på kandidatadressen før revisjonen fikk 100 % av den private trafikken. Ny full Terraform-plan er **No changes**.

Med den nye listen er sesjons-IP stabil når forfalskede verdier varierer, på begge veier. Direkte trafikk bruker en offentlig IPv6-adresse; domenet bruker en offentlig IPv4-adresse, ikke ALB-IP-en. Rå klientadresser og sesjonsdata er ikke lagt i repo/Board. Etter ny gcloud-innlogging 16.11.2026 er sammenligningen mot Googles requestlogger fullført: 71 loggposter dekker alle fire syntetiske testsesjoner, uten flere resultatsider. Hver sesjons-IP matcher den respektive `httpRequest.remoteIp` fra Google. Dermed er både direkte IPv6 og ALB-IPv4 kontrollert mot uavhengig loggmetadata, med varierende forfalskede klient- og ALB-ledd. Klient-IP-krysskontrollen er bestått. Public IAM, schedules og `EKKO_DEPLOY_ENABLED` er fortsatt av.

En separat levende SSO-rategrensetest brukte tvungen IPv4 via domenet og IPv6 direkte. Ti ALB-kall med varierende falske klient- og ALB-ledd fikk 302; det ellevte fikk 429. Deretter fikk direkte IPv6-trafikk fortsatt 302. Dette bekrefter at forfalskning ikke gir ny kvote, og at en annen faktisk klientadresse ikke deler den oppbrukte kvoten. Testen fulgte ikke Google-redirecten og erstatter ikke ekte Google-innlogging. Aktiv trafikk er fortsatt 100 % på `ekko-web-00006-4jh`, med `REMOTE_ADDR,35.190.63.68`; anonym Cloud Run-tilgang er fortsatt av.

Siste lokale kontroller: **1657 tester / 9523 assertions**, Pint/Larastan, frontend-lint, fire Chromium-tester, deploy-mocktester og Terraform fmt/validate består. Én lokal kjøring ble forstyrret fordi PHP- og nettlesertestene brukte samme testdatabase samtidig; de ble kjørt sekvensielt etterpå. En senere kjøring traff Composers prosesstidsgrense; siste fulltest brukte bare en høyere lokal tidsgrense, uten å endre tester eller CI. IAM/HTTP-testen har nå et eget transporttrinn, slik at den ikke kan bestå på grunn av manglende fixturekonfigurasjon.


### Ekte Google-konto før og etter invitasjon

16.11.2026 fullførte eieren Googles ekte innlogging og samtykke i et separat testvindu. Kontoen hadde først verken bruker, invitasjon eller Google-kobling i Ekko. Callbacken ga appens melding om manglende invitasjon. Databasen hadde fortsatt bare de to syntetiske brukerne, ingen Google-koblinger og ingen invitasjon til testkontoen: avslaget opprettet ingenting.

Deretter ble én fersk `reader`-invitasjon opprettet direkte som testfixture i den eksisterende syntetiske tenanten med null kvote og ingen prompts; ingen invitasjons-e-post ble sendt. Samme Google-økt ble brukt igjen. Dashboardet med den syntetiske merkevaren ble rendret. Databasekontrollen bekreftet nøyaktig én ny bruker med verifisert e-post, én Google-kobling, kun ett medlemskap (`reader`) i riktig testtenant, riktig `current_team_id`, ingen plattformadmin-/eierrettigheter og at invitasjonen var konsumert. Kontoen står igjen som testtenantens leser. Ingen AI-innsamling ble startet.

**Begrensning:** Cloud Run var privat gjennom hele testen. Den lokale transporten la kortlevd IAM-token på enkeltforespørsler til eksakt HTTPS-domene; den endret ikke appens OAuth-validering eller tilgangskontroller. Interseptert redirect til Google hang først; en lokal mellomside med den uendrede autorisasjonslenken lot Google-flyten fortsette. Redirect tilbake fra Google og deretter til login/dashboard manglet IAM-header og fikk Cloud Run 403. Manuell omlasting i samme nettleserøkt sendte headeren og lot appen behandle callback og vise resultatet. Det beviser ekte Google-utveksling, avslag og invitasjonsaksept, men **ikke en uavbrutt offentlig nettleserflyt**. Ved offentlig åpning må hele Google-flyten gjentas i vanlig nettleser uten denne testtransporten. Ingen IAM-, Terraform- eller produktkode ble endret i denne kontrollen. Kontoidentifikatorer, koder, token og sesjonsdata er ikke lagt i repo eller Board.
