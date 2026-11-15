# T39 – Sikkerhetsgjennomgang

Gjennomgått: `main` på `a500e57` (15.11.2026), etter at T25, T33, T34 og T41 er merget.
Metode: kodelesing av hele kjeden fra rute eller jobb til database og utgående kall, `php artisan route:list` for faktisk middleware, `git log -p` for hemmeligheter, og Terraform og workflows i `infra/` og `.github/`. Ingen kall mot produksjon. Hvert funn er kontrollert mot koden før det ble registrert.

Kravet i spesifikasjonen (kapittel 20, T39) er at funnene registreres som oppgaver på Board og lukkes før lansering. Oppgavene under er lagt inn som avhengigheter for T40 (task:41).

## Sammendrag

Ingen kritiske eller alvorlige funn. Vi fant ingen vei til en annen tenants data. Begge isolasjonslagene (Eloquent-scope og RLS) holder på alle stiene vi har fulgt. Funnene gjelder minste privilegium i drift, kontoovertakelse fra en kapret økt, tilgjengelighet (rategrenser og MCP-registrering), revisjonslogg og logging.

| Board | Funn | Alvor | Område |
|---|---|---|---|
| task:50 | T39-F1 – Fjern BYPASSRLS-legitimasjon fra `ekko-web` | Middels | Infra, isolasjon |
| task:51 | T39-F2 – Oppstartssjekk av databaserollen og vern mot `pgsql_admin` | Lav | Isolasjon |
| task:52 | T39-F3 – Klientens IP bak Cloud Run (`trustProxies`) | Middels | Innlogging, MCP |
| task:53 | T39-F4 – Vern mot kontoovertakelse fra kapret økt | Middels | Innlogging |
| task:54 | T39-F5 – MCP-registrering og anti-framing | Middels | MCP |
| task:55 | T39-F6 – Revisjonslogg og sletting av tenant | Middels | Sporbarhet, personvern |
| task:56 | T39-F7 – Strukturert logging og vasking av unntak | Middels | Logger |
| task:57 | T39-F8 – Tilganger for worker-start og nettverk for Cloud SQL | Middels/lav | Infra |
| task:58 | T39-F10 – Småherding: webhook, plattformnøkkel, tokens, uttrekk og MCP-data | Lav | Flere |
| task:59 | T39-F9 – Herding av GitHub-workflows | Lav | CI |

## Funn

### F1 – `ekko-web` har legitimasjonen til migreringsrollen (middels)
`infra/terraform/foundation.tf:149` og `workloads.tf:37,67` gir web `DB_ADMIN_PASSWORD` og `DB_ADMIN_USERNAME=ekko_migrate`. Rollen eier tabellene og har BYPASSRLS. Ingen kode bruker forbindelsen `pgsql_admin` (`config/database.php:125`). Hvis web kompromitteres (sårbarhet i en avhengighet, en feil som viser miljøet), har angriperen alle tenants data forbi begge lag. Spec 5.3 begrenser rollen til `ekko-migrate` og loggede admin-handlinger.

### F2 – RLS utenfor transaksjon avhenger av innloggingsrollen (lav)
Kode utenfor `TenantRls::run()` kjører som innloggingsrollen. Det gjelder `CollectEngineRun`, `EvaluateTenantAlerts`, `RunOnboardingAnalysis`, Dispatcher og Planner, og MCP utenfor verktøyets `run()`. Med `ekko_app` ser den ingen rader, men en feil i `DB_USERNAME` ville gjort at RLS ikke stopper noe, uten at noen merker det. Ingenting sjekker rollen ved oppstart. Tabellene `mcp_connections`, `mcp_auth_codes`, `team_invitations` og `team_user` bruker `team_id` og fanges ikke av `RlsAudit`. Tilgangen er i dag riktig filtrert i appen.

### F3 – Rategrenser per IP blir felles bak Cloud Run (middels)
`bootstrap/app.php` setter ikke `trustProxies`. Bak Cloud Run er `$request->ip()` trolig fronten til Google. `throttle:sso` (10/min), `mcp-oauth` (30/min, også fornyelse av tokens) og innloggingsgrensen (5/min per e-post og IP) blir da felles for alle. Én aktør kan stenge Google-innlogging og fornyelse av MCP-tokens, og holde en bestemt konto låst. Dette må verifiseres med røyktesten i T10.

### F4 – Kontoovertakelse fra en kapret økt (middels)
- `PUT user/profile-information` bytter e-postadresse uten passord (`app/Actions/Fortify/UpdateUserProfileInformation.php`).
- Fortify-rutene (profil, passord, `confirm-password`, `two-factor.*`, `email/verify`) har bare `auth:web`, ikke `AuthenticateSession`. En kapret økt overlever derfor «logg ut andre økter» og passordbytte. Kjeden e-postbytte → verifisering → «glemt passord» gir kontoen til angriperen.
- `POST user/confirm-password` har ingen rategrense. Den låser opp «opptre som tenant» og endringer i 2FA.
- `POST forgot-password` viser om en e-post har konto, og har ingen IP-grense.

### F5 – MCP-registrering og anti-framing (middels)
- `POST /oauth/register` krever ikke innlogging og har ett tak for hele appen (500 per døgn, `OAuthController.php:94`). Med 30/min fra én IP er taket nådd på omtrent 17 minutter. Da får alle nye Claude-tilkoblinger 429 i et døgn. Ubrukte klienter slettes aldri.
- Ingen `X-Frame-Options` eller CSP `frame-ancestors`, så samtykkesiden `/oauth/authorize` kan rammes inn. `SameSite=Lax` demper risikoen fra andre nettsteder, men ikke fra samme nettsted.

### F6 – Revisjonslogg og sletting av tenant (middels)
- Revisjonsloggen mangler sendte og kansellerte invitasjoner, opprettelse, nytt navn og sletting av tenant, og endringer i API-tokens (spec 16).
- `DELETE /teams/{team}` kjører `purge()` med en gang, uten passordbekreftelse og uten logg. Spec 16 sier at data slettes 30 dager etter stenging, med eksport først.
- Råsvar i GCS slettes ikke når tenanten slettes, og blir liggende til regelen om 400 dager.

### F7 – Logging (middels)
`LOG_CHANNEL=stderr` uten formatterer gir linjetekst, ikke strukturert JSON. Ingen felles kontekst gir `tenant_id`, `user_id` og `run_id`, og `LOG_LEVEL` er `debug`. Generelle `report($e)` kan logge `QueryException` med SQL-verdier, for eksempel svartekst og e-postadresser. Begge deler er i strid med spec 16.

### F8 – Tilganger for worker-start og Cloud SQL (middels/lav)
Web har `roles/run.jobsExecutorWithOverrides` på `ekko-worker` (`workloads.tf:188-195`). Et kompromittert web-lag kan starte workeren med egne miljøvariabler og hente ut råsvar og motornøkler gjennom workerens identitet. Cloud SQL har ingen `ip_configuration`. Den har derfor offentlig IPv4, og verken TLS eller connector håndheves.

### F9 – GitHub-workflows (lav)
`claude.yml` og `ci.yml` bruker actions uten pinnet commit-SHA. `agent-review.yml` sjekker ikke at PR-en kommer fra samme repo eller fra en medlem, så alle som kan åpne en PR, bruker review-kreditter.

### F10 – Småherding (lav)
- `SlackNotifier::accepts` (`app/Alerts/SlackNotifier.php:22`): `! isset($a, $b, $c)` avviser bare når alle tre er satt, så port eller userinfo slipper gjennom. Verten er fortsatt låst til `hooks.slack.com`.
- Plattformens DataForSEO-nøkkel lagres kryptert i `runs.provider_api_key` mens kjøringen pågår (`QueuedEngineCollector.php:98-100,133`). Den bør bare lagres ved BYOK.
- REST-tokens utløper ikke, og grensen er per token, så flere tokens gir mer kvote. Dette er i tråd med spec 15.1, men bør herdes med en grense per bruker.
- Uttrekket lagrer modellsitater og ukjente merkevarer som ikke står i svaret. En tredjepart som påvirker motorens svar, kan forskyve tenantens kildeandeler og varselet om ny konkurrent. Det gjelder bare tenantens egne data.
- MCP-verktøy returnerer motorens tekst uten et felt som merker den som upålitelig data.

## Kontrollert og i orden

- **Isolasjon og RLS:** alle 34 tabeller med `tenant_id` har ENABLE og FORCE RLS med én permissiv policy, og tom `app.tenant_ids` gir ingen rader. Alle fremmednøkler mellom tenanttabeller går på `(tenant_id, id)`. Alle modeller med `tenant_id` bruker `BelongsToTenant`. `TenantBuilder` stenger farlige masseoperasjoner. Rå SQL kjører per tenant eller mot plattformtabeller. Middleware-rekkefølgen gjør at ruteparametre løses etter tenant og RLS. Alle jobber arver `TenantJob`.
- **Roller og innlogging:** ingen rolleeskalering. Partner-rådgiver kan ikke gis i kundetenanter. Invitasjoner er bundet til verifisert e-post, utløper etter 7 dager og er signerte. Google-SSO krever `email_verified`. 2FA håndheves for web, API v1, OAuth-samtykke og MCP. «Opptre som tenant» krever begrunnelse og passordbekreftelse, logges og kan ikke bli et token. Cookien er `__Host-` og secure, og det er ingen unntak fra CSRF.
- **Nøkler og tokens:** egne nøkler, webhooker og integrasjonskonfigurasjon er krypterte og skjulte, og maskeres i revisjonsloggen. API v1-tokens er bundet til nøyaktig én tenant, og tilgang og rolle sjekkes på hvert kall. MCP-tokens og REST-tokens kan ikke brukes på hverandres flater.
- **SSRF og inndata:** veiviserens henting har bare https/443, alle oppslag må være offentlige, IPv6 tillates bare fra en liste, adressen festes med `CURLOPT_RESOLVE`, proxy er av, hver omdirigering kontrolleres, og det er grense både før og etter dekomprimering. Andre utgående kall går til faste verter uten omdirigering. Opplasting av robotlogger har grenser og lagres ikke på disk. Det finnes ingen `v-html` med motorinnhold, og e-post og Slack escapes.
- **MCP og OAuth:** `redirect_uri` må stemme eksakt, PKCE krever S256, koder er hashede, brukes én gang og gjelder i 5 minutter. Refresh-tokens roteres, og gjenbruk oppdages. Tenant og rolle sjekkes på nytt ved hvert verktøykall.
- **Hemmeligheter og infra:** ingen hemmeligheter i treet eller i git-historikken. Ingen owner- eller editor-roller og ingen nøkler for tjenestekontoer. Region er `europe-west1`. Bøttene har offentlig tilgang sperret, og råsvar har livssyklus på 400 dager. Scheduler-endepunktet verifiserer OIDC-utsteder, publikum og e-post. `agent-review.yml` har `permissions: {}` og pinnede actions.

## Ikke dekket

- Deploy-workflow og vilkår for Workload Identity Federation finnes ikke ennå (T10). Når de finnes, må de gjennomgås.
- Verdien av `REMOTE_ADDR` i Cloud Run (F3) og TLS mot Cloud SQL i drift må verifiseres i produksjon.
- Frontend for svar og kilder er ikke laget ennå (T27). Lenker skal ha `rel="noopener noreferrer nofollow"` og bare http(s), slik spec 16 krever.
- Kravene til skjemaene for webhook, egne nøkler og integrasjoner er sendt til T28 (Board #446).
