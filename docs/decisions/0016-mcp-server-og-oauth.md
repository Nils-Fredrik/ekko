# 0016 – MCP-server med egen OAuth-server, tenantvalg og skrivetilgang

- **Status:** Akseptert
- **Dato:** 2026-11-15
- **Board:** `task:39` / T34

## Kontekst

`docs/spec.md` 15.2 beskriver en MCP-server på `/mcp` med `laravel/mcp`, OAuth 2.1 via Passport, samtykkeside og tilkoblingsoversikt som i Varde, og tolv verktøy. Tokenet skal bære brukerens rettigheter og valgt tenant, og partnerrådgivere skal kunne velge en kunde-tenant.

Passport kan ikke brukes på samme `User` som Sanctum. Begge `HasApiTokens`-traitene definerer `$accessToken` med ulik type, og Jetstreams API-tokens (15.1) trenger Sanctum. Varde traff det samme problemet og valgte bort Passport.

## Beslutning

1. **Egen autorisasjonsserver, som i Varde.** `App\Http\Controllers\Mcp\OAuthController` og `App\Mcp\McpTokens` dekker kravene Claude stiller:
   - metadata (RFC 9728 og RFC 8414)
   - dynamisk klientregistrering (RFC 7591) for offentlige klienter
   - autorisasjonskode med PKCE S256
   - refresh-nøkler som byttes ved hver bruk
   - frakobling (RFC 7009)

   | Endepunkt | Hva |
   |---|---|
   | `GET /.well-known/oauth-protected-resource/mcp` | Ressursen er `…/mcp`, autorisasjonsserveren er Ekko |
   | `GET /.well-known/oauth-authorization-server` | Endepunktene, `S256`, offentlige klienter |
   | `POST /oauth/register` | Bare Claudes callback-adresser godtas (`McpRedirects`). Claude.ai og claude.com, og lokal adresse med vilkårlig port for Claude Code |
   | `GET/POST /oauth/authorize` | Vanlig innlogging (med tofaktor der det kreves), deretter samtykke |
   | `POST /oauth/token` | `authorization_code` med PKCE og `refresh_token` |
   | `POST /oauth/revoke` | Frakobling |

2. **Nøklene.**
   - **Tilgangsnøkkelen** er en Sanctum-nøkkel med navnet `Claude (MCP)` og evnen `mcp`, pluss `mcp:write` når brukeren tillot endringer. Den gjelder i én time og virker bare på `/mcp` (`AuthenticateMcp`). Sanctum-vakten avviser nøkler med evnen `mcp` overalt ellers (`Sanctum::authenticateAccessTokensUsing` i `AppServiceProvider`), også på samtykkesiden, så en lesetilkobling ikke kan godkjenne en bredere tilkobling.
   - **Vanlige API-nøkler** virker ikke på `/mcp`. Claude-nøklene vises ikke under API-nøkler (`User::tokens()`).
   - **Refresh-nøkkelen** gjelder i 30 dager etter siste bruk, og bare hashen lagres. Brukes en refresh-nøkkel som allerede er brukt, kobles tilkoblingen fra (RFC 9700 4.14.2). Tilkoblingen husker de siste 50 brukte hashene.
   - **Engangskoden** gjelder i fem minutter. Brukes den på nytt, kobles bare tilkoblingen den ga fra (`mcp_auth_codes.mcp_connection_id`).
   - **Tabellene** `mcp_clients`, `mcp_auth_codes` og `mcp_connections` tilhører brukeren, som API-nøkler, og er ikke tenant-eide.
3. **Tenanten velges på samtykkesiden.** Brukeren velger blant tenantene i tenant-velgeren, uten personlige team. Valget lagres på tilkoblingen (`team_id`).
   - Hvert verktøykall arbeider i den valgte tenanten. Argumentet `tenant` kan peke på en av tenantene `TenantAccess::rlsTenantIds()` gir for den valgte. For en partnerrådgiver i partner-tenanten er det kunde-tenantene, og for alle andre bare den valgte.
   - Tilgangen sjekkes på nytt ved hvert kall, så mistet medlemskap gjelder med en gang.
   - Verktøyet kjøres med tenanten i `TenantContext` og under RLS for bare den tenanten (`TenantRls::run([$id])`).
   - Mens en plattformadmin opptrer som en tenant, kan Claude ikke kobles til.
4. **Rettigheter.**
   - Hvert verktøy har en `TenantPermission`, sjekket med `TenantAccess` for rollen i tenanten. `get_usage` krever `ViewUsage`, `upsert_prompts` krever `ManagePrompts`, og de andre krever `ViewData`. `whoami` krever ingen.
   - Skrivende verktøy vises og kan kalles bare når tilkoblingen tillater endringer. `upsert_prompts` sjekker i tillegg `PromptPolicy`.
5. **Logging.**
   - Endringer gjennom `upsert_prompts` får egne revisjoner (`RecordsRevisions`, med prompteksten maskert) med brukeren som utfører.
   - De får i tillegg en oppføring `mcp.upsert_prompts` i revisjonsloggen med kilden `mcp`, antall, id-er og tilkoblingen (`AuditLog::mcpChange`).
   - Ingen tekster logges.
6. **Svar.**
   - Hvert verktøy svarer med en kort tekstoppsummering (fra språkfilene) og strukturert JSON (`structuredContent`, som også ligger som JSON-tekst).
   - Lister som kan bli store, pagineres med en `cursor` (en forskyvning, base64), og svaret har `next_cursor`. `get_responses` gir fem svar per side, og svarteksten kuttes etter 8000 tegn.
   - Feil gis som korte meldinger til Claude, aldri som unntak.
7. **Avgrensninger.**
   - **`compare_competitors`:** gir share of voice, synlighet og antall nevnelser per konkurrent (`MetricsService::competitorComparison`). Posisjon og anbefalingsrate gis bare for merkevaren, fordi `agg_daily` ikke har de tellerne for konkurrenter.
   - **`propose_prompts`:** skriver ingenting og kaller ingen modell. Verktøyet gir grunnlaget (promptene som finnes, dekningen per intensjon og trakttrinn, plassen i pakken og reglene), og Claude lager forslagene.
   - **`upsert_prompts`:** et prompt kan ikke flyttes til et annet marked, fordi svarene og aggregatene hører til markedet og merkevaren. Pakkens `max_prompts_per_market` håndheves. Et kall som øker antallet aktive prompts over grensen, avvises, og da lagres ingenting. Et marked som allerede er over grensen, kan fortsatt redigeres.
8. **Tilkoblingsoversikten** ligger på `/claude` (`Pages/Mcp/Connections.vue`, lenke i brukermenyen). Brukeren ser bare egne tilkoblinger og kan koble dem fra.
9. **Grenser.** Kall til `/mcp` er begrenset per bruker (`ekko.mcp.calls_per_minute`). OAuth-endepunktene er begrenset per adresse. Klientregistreringen er begrenset per adresse per døgn (`ekko.mcp.clients_per_ip_per_day`). Klienter uten tilkobling slettes etter en time (`ekko.mcp.unused_client_minutes`), og bare de teller mot taket for hele appen (`ekko.mcp.unused_clients_max`). Fyller mange adresser taket, sperres nye tilkoblinger i høyst en time, ikke et døgn (T39-F5).

## Konsekvenser

- `docs/spec.md` 4, 15.2 og T34 sier nå egen OAuth-server i stedet for Passport. Avviket er registrert på Board.
- **Nye MCP-tools:**
  - Arv `App\Mcp\EkkoTool` og sett `$permission`.
  - Før verktøyet inn i `tests/Feature/TenantIsolation/mcp-tools.php` med rettighet og argumenter, og i `EkkoServer::$tools`.
  - `McpToolIsolationTest` kaller alle verktøyene som alle roller i tenant A, med A's id-er, med B's id-er og med B som tenant.
- **Eieren må verifisere tilkoblingen fra Claude.** Legg `https://<domene>/mcp` til som custom connector i Claude, koble til og kall `whoami`. Det kan ikke testes i CI. Feature-testene dekker hele OAuth-flyten mot appen.
- **Konkurrenter:** posisjon og anbefalingsrate for konkurrenter krever nye tellere i `agg_daily`. Det er en egen oppgave om det trengs.
