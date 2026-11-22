# Arbeidsflaten i Ekko (U16–U27)

Status: **spesifikasjon, klar for Board**. Skissene er godkjent av eier 21.11.2026.

Denne spesifikasjonen gjelder hvordan brukeren vedlikeholder data og arbeider i Ekko. Den bygger på `docs/spec.md` (kapittel 5, 6, 7.6, 11 og 14) og `docs/ui-forbedring.md` (U01–U15). Uttrykket er fortsatt Varde og Borg (spec 14.1). Det som endres, er strukturen, arbeidsflyten og komponentene for vedlikehold.

Ved motstrid gjelder denne rekkefølgen:

1. Kravene i dette dokumentet.
2. Skissene.
3. `docs/spec.md` for alt dette dokumentet ikke omtaler.

Avvik fra spesifikasjonen registreres på Board og føres inn her i samme PR (AGENTS.md).

## 0. Skisser

| Skisse | Kildefil i repoet | Skjerm |
|---|---|---|
| 1 · Merkevareoversikt med helsehode | `docs/ux-skisser/Main.dc.html` | 5.1 |
| 2 · Prompts og temaer | `docs/ux-skisser/Prompts.dc.html` | 5.2 |
| 3 · Ukjente merkevarer til behandling | `docs/ux-skisser/Triage.dc.html` | 5.3 |
| 4 · Markeder og måleplan | `docs/ux-skisser/Plan.dc.html` | 5.4 |
| 5 · Innboks | `docs/ux-skisser/Inbox.dc.html` | 5.6 |

Skissene ligger også som klikkbart lerret i Claude Design: [Ekko arbeidsflate – skisser](https://claude.ai/artifact/MnGLCPDupdsqNmDGD3CVpv). Lenken er privat. Agentene bruker filene i repoet:

- **Bilder:** `docs/ux-skisser/png/<navn>.png` viser hver skisse i 1440 px bredde, mørkt tema.
- **Klikkbar versjon:** åpne `docs/ux-skisser/<navn>.dc.html` direkte i en nettleser.
  - `docs/ux-skisser/support.js` er en liten lokal renderer for malene. Den har ikke noe med appen å gjøre.
  - Skriftene lastes fra `public/fonts`.
  - Sidemenyen lenker mellom skissene. Skisse 2, 3 og 5 kan klikkes i.
- **Nye bilder** etter en endring i en skisse lages ved å åpne filen i Playwright (Chromium) med 1440×1080 og ta skjermbilde av hele siden.

**Slik leser du kildefilene**

- Hver fil er HTML med inline-stiler, og innholdet ligger i `<x-dc>`. Testdataene står i `renderVals()` nederst. Layouten, avstandene, tekststørrelsene og rekkefølgen er fasit.
- Fargene er skrevet som hex. Implementasjonen bruker tokenene i tabellen under, aldri hex, fordi lint-regelen fra U01 stopper hardkodede farger. Lyst tema følger av tokenene.
- Dataene er syntetiske. Fjordbyrå og konkurrentene finnes ikke. Tall og tekster i skissene er eksempler, ikke krav.

| Hex i skissen | Token | Tailwind |
|---|---|---|
| `#000000` | `--color-shell`, `--color-shell-panel` | `bg-shell`, `bg-shell-panel` |
| `#0b0909` | `--color-shell-surface` | `bg-shell-surface` |
| `#1a1412` | `--color-row-hover` | `bg-row-hover` (valgt rad, aktiv meny, brikke) |
| `#4a3f3a` | `--color-line` | `border-line` |
| `#2e2624` | `--color-line-soft` | `border-line-soft`, `bg-line-soft` (spor i fremdriftslinjer) |
| `#ffffff` | `--color-shell-fg` | `text-shell-fg` |
| `#f5ede4` | `--color-shell-text` | `text-shell-text` |
| `#c4b6a8`, `#a8998c` | `--color-shell-muted` | `text-shell-muted`. Skissene har to dempede nivåer, men implementasjonen bruker ett. |
| `#ffb6c3` | `--color-shell-accent` | lenker og «Angre» |
| `#c73823` | `--color-primary-deep` | primærknapp, merketall |
| `#e84a38` | `--color-primary`, `--data-own` | fokusramme, egen serie, valgt fane |
| `#5d8a6a` | `--color-success-text` | «Lagret», fullført, positiv endring |
| `#bd6861` | `--color-error-text` | feil, «Arkiver», negativ endring |
| `#c97b3e` | `--color-warning` | statuskant for varsler, ulagrede endringer |
| `#e2b8a5` | `--color-medium-beige` | statuskant for nye merkevarer, andelsstolpe |
| `#c46b7a`, `#a85e2e` | `--data-marker-action`, `--data-marker-model` | statuskant for tiltak og modellbytte |
| `#301411`, `#551f19` … | `--data-seq-1` … `--data-seq-6` | heatmapceller, uthevet navn i utdrag |
| `#a8805a` … `#7d6248` | `--data-cat-1` … `--data-cat-6` | konkurrentfarger |
| `#8a7568` | `--data-neutral` | «Andre» i grafer |

## 1. Hvorfor

Gjennomgangen av ekko.maksimer.com 21.11.2026 viste dette:

- **Datamodellen kan ikke vedlikeholdes i grensesnittet.**
  - Temaer har ingen ruter.
  - Prompts kan ikke opprettes eller slettes. Det går bare an å importere CSV og redigere én og én.
  - Markeder kan bare opprettes i veiviseren.
  - Konkurrenter kan bare legges til og slettes.
- **Ukjente merkevarer vises som en vegg** med mer enn 50 kort som bare har «Legg til». Det finnes ingen måte å si «sett, ikke en konkurrent» på.
- **Innstillingene er ett langt skjema med én Lagre-knapp.** Varselregler og rapportplaner hører til en merkevare, men vedlikeholdes på globale sider.
- **Synlige feil:**
  - Varsler viser interne nøkler (`market:7:name:028d…`).
  - Forbruk viser rå tall (`16.53743710 USD`).
  - Menypunktet heter «Dashbord».
  - Standardperioden er 28 dager selv med fire dager data.
  - Forklaringen til share of voice overlapper aksen.
  - Promptlisten blander markeder.
- **Ingenting fører brukeren til Tiltak og Faktasjekk.** Begge står derfor tomme.

## 2. Prinsipper

1. **Én arbeidsflate per merkevare.** Merkevare og marked velges én gang i sidemenyen og gjelder alle sider.
2. **Analyse og oppsett er adskilt.** Hver tabell i datamodellen har ett sted der den vedlikeholdes.
3. **Arbeid i lister, ikke skjemaer.** Det som finnes i mange eksemplarer, redigeres i tabeller med flervalg, handlinger på mange rader og detaljpanel.
4. **Lagring der endringen skjer.** Enkeltfelt lagres når de endres, med «Lagret» og angre. Felles lagring brukes bare for måleplanen.
5. **Én innboks for det som krever handling.**
6. **Lesbare tall og tekster overalt.** All formatering går gjennom ett lag. Interne nøkler vises aldri.

## 3. Gjennomføring

### 3.1 Mange oppgaver, ikke én

Arbeidet deles i tolv oppgaver (U16–U27):

- **Én oppgave = én gren = én PR** (spec 19.2). Hver PR får kryssreview og CI. En samlet PR på flere tusen linjer kan ikke reviewes skikkelig.
- **Backend og grensesnitt kan gå parallelt.** Kontrakten (ruter, request og svar) står i kapittel 6, og den deles.
- **Visuell regresjon styrer rekkefølgen for UI.** Referansebildene i `tests/Browser/visual/snapshots/` versjoneres i PR-en (`docs/ui-quality.md`).
  - Skallet (U17) endrer alle bildene (176 etter U18). Derfor går U17 alene: ingen annen UI-PR merges mens U17 er åpen.
  - Etter U17 endrer hver UI-oppgave bare bildene for sine egne sider. Ved konflikt rebases PR-en, og bare egne bilder genereres på nytt.
- **Oppgavene er vertikale der det lønner seg.** Samme agent tar backend og grensesnitt for ukjente merkevarer (U21 og U22), fordi modellen og arbeidsflyten henger tett sammen.

### 3.2 Agenter

Fordelingen bygger på hva agentene har levert før:

- **Claude Code:** gjenkjenning (T18, T20), varsler (T24) og grafbiblioteket (U02).
- **Codex:** API og MCP (T33, T50), filterlinjen (U11) og visuell regresjon (U15).
- **Cursor:** dashboard, prompts, rapportdesign, kommandopalett og måleplanens grensesnitt (U04–U06, U13, U14, T55).

| Nr | Oppgave | Agent / modell | Avhenger av |
|---|---|---|---|
| U16 | Raske rettinger (kapittel 7) | Cursor / Grok 4.7 | – |
| U17 | Skall, sidemeny og omfangsvelger (4.1) | Claude Code / Opus 5.5 | U16 |
| U18 | Felles komponenter og formatering (4.2–4.6) | Claude Code / Opus 5.5 | – |
| U19 | API for temaer og prompts (6.2) | Codex / GPT-6.1 Sol | – |
| U20 | Prompts og temaer (5.2) | Cursor / Grok 4.7 | U17, U18, U19 |
| U21 | Beslutninger om ukjente merkevarer og konkurrent-API, med ADR (6.3) | Claude Code / Opus 5.5 | – |
| U22 | Konkurrenter og merkevarer (5.3) | Claude Code / Sonnet 5.5 | U17, U18, U21 |
| U23 | API for markeder, identitet, integrasjoner og matchtest (6.4) | Codex / GPT-6.1 Sol | – |
| U24 | Markeder og måleplan (5.4), identitet (5.5) og integrasjoner (5.8) | Cursor / Grok 4.7 | U17, U18, U23 |
| U25 | Innboks, helse, varselregler og rapportplaner per merkevare (6.5) | Codex / GPT-6.1 Sol | U16, U21 |
| U26 | Innboks (5.6), helsehode og oppstartsliste (5.1), varsler og rapporter (5.7) | Cursor / Grok 4.7 | U17, U18, U25 |
| U27 | Opprydding, tilgjengelighet og mobil (kapittel 8) | Codex / GPT-6.1 Terra | U20, U22, U24, U26 |

Hver agent får fire oppgaver.

Lagt til etter planen: **U28** Svarliste per sentiment (Claude Code / Opus 5.5, ingen avhengighet) gjør sentimentet på konkurrentsiden klikkbart til svarene bak tallet (`docs/spec.md` 14.2, `Pages/Brands/Responses.vue`). **U29** Begrunnelse for sentiment (Claude Code / Opus 5.5, avhenger av U28) lar uttrekket lagre et kort sitat som begrunner sentimentet og viser det i svarlisten.

**Bølger:**

| Bølge | Oppgaver |
|---|---|
| 1 | U16 (Cursor), U18 og U21 (Claude Code), U19 og U23 (Codex) |
| 2 | U17 når U16 og U18 er merget |
| 3 | U20, U22, U24 og U25 |
| 4 | U26, deretter U27 |

**Modellvalg:** Opus 5.5 brukes der det kreves arkitektur og en ADR (U17, U18 og U21). Sonnet 5.5 brukes for U22, som følger et ferdig mønster.

### 3.3 Felles regler for alle oppgavene

- **Ny side:** står i sideregisteret `tests/Browser/visual/pages.mjs` med lys og mørk variant på desktop og mobil, og uten kritiske axe-funn.
- **Ny rute:** står i `tests/Feature/TenantIsolation/routes.php` med tilgangsklasse og test av en annen tenants ressurs.
- **Tekst:** står i `lang/{nb,sv,en}/`, ny fil `workspace.php` for skall og felles komponenter. Hardkodet tekst i komponenter er ikke tillatt.
- **Tilgang:** sjekkes i policyene på serveren. Grensesnittet skjuler endringsknapper for roller uten tilgang, men serveren avgjør. Tillatelsene er `prompts.manage`, `competitors.manage`, `brands.manage`, `markets.manage`, `alerts.manage` og `reports.manage` (`App\Enums\TenantPermission`).
  - En leser ser oppsettssidene uten endringsknapper.
  - En redaktør kan endre prompts, temaer og konkurrenter.
- **Ingen hemmeligheter, kundedata eller råsvar** i testdata, skjermbilder eller PR-er. Testdata er syntetiske.
- **PR-beskrivelsen** viser skjermbilde av siden i mørkt tema ved siden av skissen.

## 4. Felles grunnlag

### 4.1 Skall (U17)

**Fil:** `Layouts/AppLayout.vue` bygges om etter skissene. `Components/Brand/BrandNav.vue` fjernes. Toppmenyen erstattes av sidemenyen. Jetstream-menyene for team og bruker flyttes inn i sidemenyen.

**Oppbygging, ovenfra og ned (skisse 1, venstre kolonne, 248 px):**

1. **Logo og tenantvelger.** Logoen «Ekko» lenker til `dashboard`. Tenantvelgeren er en pille med tenantnavn og en meny med tenantene brukeren har tilgang til (dagens `current-team.update`).
2. **Søk.** Knapp med ikon, teksten «Søk eller kjør handling» og `⌘K` eller `Ctrl+K`. Den åpner `CommandPalette` (U14).
3. **Globale lenker:**
   - Portefølje (`dashboard`, eller `partner.index` for partner-rådgivere).
   - Innboks (`inbox.index`) med tall i rød pille (`bg-primary-deep`). Tallet vises bare når det er over 0.
4. **Omfangsvelger** (kort med `border-line` og `bg-shell-surface`) med to knapper:
   - **Merkevare** med navn og en meny med tenantens merkevarer og «Ny merkevare» (veiviseren).
   - **Marked** med «Alle markeder» og hvert marked som «Norge», «Sverige» osv. Etiketten er `location_label` hvis den finnes, ellers landsnavnet.
   - Uten valgt merkevare (globale sider) vises bare merkevareknappen, med «Velg merkevare».
5. **Gruppene Analyse, Arbeid og Oppsett** (overskrift 11 px, versaler, `tracking-[0.08em]` som i skissen). Lenkene står i tabellen under.
6. **Forbruk:** «Forbruk *måned*», prosent, linje (4 px) og «*kall* av *kvote* kall · *beløp*». Formateres med `format.js`. Lenker til `usage.index`.
7. **Bruker:** initialer, navn og rolle. Menyen har profil, API, MCP-tilkoblinger, tema, språk, plattformadmin (bare for plattformadmin) og logg ut.

**Lenker per merkevare:**

| Gruppe | Etikett | Rute | Merketall |
|---|---|---|---|
| Analyse | Oversikt | `brands.show` | – |
| Analyse | Prompts | `brands.prompts.index` | – |
| Analyse | Konkurrenter | `brands.competitors.index` | – |
| Analyse | Kilder | `brands.sources` | – |
| Analyse | Roboter og trafikk | `brands.crawlers` | – |
| Arbeid | Tiltak | `brands.actions.index` | tiltak med `status = new` |
| Arbeid | Faktasjekk | `brands.facts.index` | – |
| Oppsett | Prompts og temaer | `brands.setup.prompts` | – |
| Oppsett | Konkurrenter og merkevarer | `brands.setup.competitors` | ukjente merkevarer som venter |
| Oppsett | Markeder og måleplan | `brands.setup.markets` | – |
| Oppsett | Identitet | `brands.setup.identity` | – |
| Oppsett | Integrasjoner | `brands.setup.integrations` | integrasjoner med feil |
| Oppsett | Varsler og rapporter | `brands.setup.notifications` | – |

- **Utseende:**
  - En aktiv lenke har `bg-row-hover`, `text-shell-fg` og `font-medium`.
  - De andre lenkene har `text-shell-muted`.
  - Merketall står i en pille med `bg-line-soft`.
- **Rute:** `brands.prompts.show` og `brands.actions.show` markerer foreldrelenken.
- **Rekkefølge av leveranser:** U17 lager rutene `brands.setup.*` (6.1) og sender de som ikke er bygget ennå, til dagens sider:
  - `setup.prompts` → `brands.prompts.index`
  - `setup.competitors` → `brands.competitors.index`
  - `setup.markets`, `setup.identity` og `setup.integrations` pekte på `brands.settings` til U24 bygde sidene
  - `setup.notifications` → `alerts.index`

  Oppgavene som bygger sidene, bytter ut videresendingen. U24 har sider for markeder, identitet og integrasjoner, og `brands.settings` sender til identitet. U26 har siden for varsler, og `inbox.index` er innboksen. `/alerts` sender til `/inbox?type=alerts`.

**Markedsomfang:**

- **URL-en er kilden.** Markedet ligger i URL-en som `?market=<id>`, slik filterlinjen gjør i dag (spec 7.8).
- **Lenker:** alle lenkene i sidemenyen tar med gjeldende `market` når siden de peker til støtter det. Analysesidene bruker `AnalysisFilters`. Oppsettssidene bruker `market` for prompts og ignorerer det ellers.
- **Bytte av marked** i omfangsvelgeren gjør et Inertia-besøk til samme rute med ny `market`. Andre filtre beholdes.
- **Siste valg** lagres i `localStorage` under `ekko.scope.<tenantId>.<brandId>`. Det brukes bare når brukeren bytter merkevare i velgeren.

**Delte data:**

- **`workspace`-propen:** `HandleInertiaRequests` deler en utsatt (`Inertia::defer`) prop `workspace` på sider i applayouten:
  ```
  workspace: {
    brands: [{ id, name }],
    brand: { id, name } | null,           // fra rute-parameteren {brand}
    markets: [{ id, label, is_active }],  // for valgt merkevare
    market_id: int | null,
    counts: { inbox, actions_new, entities_pending, integrations_failing },
    usage: { month, calls, quota, cost_usd, percent } | null,
    role: string | null,                  // rollenavnet, lagt til i U17
    can: { manage_prompts, manage_competitors, manage_brands, manage_markets, manage_alerts, manage_reports }
  }
  ```
- **Tallene** kommer fra `App\Workspace\WorkspaceCounts::for(Team, ?Brand, ?Market = null)`:
  - `inbox`: summen av ventende rader i innboksen (6.5) for valgt merkevare og marked.
  - `entities_pending`: antall navn i køen (6.3). Inntil U21 er merget, brukes antall forslag fra `UnknownBrands::suggestions` for 28 dager.
- **Ytelse:** tallene caches per tenant, merkevare og marked i 60 sekunder og nullstilles når en handling i 6.2–6.5 lagres.
- **Mobil (under 768 px):** sidemenyen er en skuff som åpnes fra en toppstripe med logo, sidetittel og menyknapp. Skuffen lukkes ved navigasjon. Filtre bruker `BottomSheet` som før.

**Akseptkriterier for U17:**

- Alle sider i applayouten har sidemenyen. Ingen side har toppmenyen eller `BrandNav`.
- Gjeldende side er markert, og merketallene stemmer med `WorkspaceCounts`.
- Markedsvalg følger URL-en, og kopiert URL gir samme valg.
- Hele sidemenyen kan brukes med tastatur. `⌘K` åpner paletten.
- Referansebildene er oppdatert for alle sider, og axe gir ingen kritiske funn.

**Slik U17 er bygget:**

- **Filer:** `Layouts/AppLayout.vue` (skall, toppstripe og skuff), `Components/Shell/Sidebar.vue` (sidemenyen), `Components/Shell/sidebarNav.js` (lenker, aktiv side, merketall og spørring, testet i `tests/js/sidebar-nav.test.mjs`) og `Composables/useWorkspace.js`. På serveren lager `App\Workspace\Workspace::for(Request)` propen og `App\Workspace\WorkspaceCounts` tallene. U24 fjernet de siste videresendingene i `WorkspaceRedirectController`.
- **Fjernet:** `BrandNav.vue` og Jetstreams ubrukte menykomponenter `NavLink`, `ResponsiveNavLink`, `Dropdown` og `DropdownLink`. Menyene i sidemenyen bruker Headless UI `Menu`, som gir piltaster, Escape og fokus tilbake til knappen.
- **Markedet** leses fra `?market` i URL-en også i nettleseren, slik at lenkene følger valget umiddelbart. Filterlinjens delvise besøk henter også `workspace`, slik at merketallene gjelder markedet i URL-en.
- **Lenkene til analysesidene** beholder analysefiltrene (periode, sammenligning, motor osv.) som `BrandNav` gjorde, og får gjeldende `market`. Roboter og trafikk, Arbeid og oppsettssidene unntatt Prompts og temaer får ikke `market`.
- **Aktiv lenke for dagens sider:** `brands.settings` markerer Identitet, `brands.plan.show` og `brands.markets.create` markerer Markeder og måleplan, og Innboks er aktiv på `alerts.*`. Etter en videresending markeres siden brukeren havner på.
- `entities_pending` er antall navn i `EntityQueue::pending`. En lagring eller sletting av en kilde i `WorkspaceCounts::SOURCES` nullstiller tellingene for tenanten etter commit. Integrasjonssynken gjør det samme når statusen endres.
- **Utsatt prop:** `workspace` deles bare med innloggede brukere med en tenant. Mens propen lastes etter et besøk, viser sidemenyen forrige verdi for samme tenant og merkevare, så den ikke blinker.
- **Avvik og tillegg:**
  - `workspace.role` er lagt til: rollenavnet under brukernavnet nederst i sidemenyen (skisse 1).
  - Partner-rådgivere får lenken «Pitch» (`pitches.index`) under Portefølje, fordi toppmenyen som hadde den, er fjernet.
  - Rapporter (`reports.index`), Merkevarer (`brands.index`) og Veiviseren nås fra kommandopaletten, Portefølje og «Ny merkevare». Sidemenyen har ingen egen lenke til dem.
  - Logoen er dagens Maksimer-merke ved siden av «Ekko», ikke den røde «e»-en i skissen.
  - Forbruk kan vise over 100 %. Linjen stopper på full bredde.
  - `workspace` deles med alle Inertia-sider for innloggede brukere med en tenant, ikke bare sidene i applayouten. Serveren vet ikke hvilken layout en side bruker. De få sidene utenfor (bekreft passord, MCP-godkjenning) får ett ekstra utsatt kall.
  - Masseoppdateringer uten modellhendelser (for eksempel omklassifisering av nevnelser og nye svar med ukjente navn) nullstiller ikke tallene. De vises senest etter 60 sekunder.
  - Valgt merkevare, marked, tenant, tema og språk i menyene har en prikk og teksten «Valgt nå» for skjermlesere (`CurrentMark.vue`).
  - Sidetitlene på dagens sider er uendret. Overskrift og «Merkevare · Gruppe» som i skissen kommer med sideoppgavene (U20–U26).

### 4.2 Formatering (U18)

Beslutningene i U18 står i ADR 0044 (`docs/decisions/0044-felles-komponenter-for-arbeidsflaten.md`).

`resources/js/format.js` er det eneste stedet tall, valuta, prosent og datoer formateres. Locale kommer fra `useLocale()` og tidssonen fra `teams.timezone`, delt som `tenant.timezone`.

| Funksjon | Eksempel (nb) | Regel |
|---|---|---|
| `formatNumber(1412)` | 1 412 | `Intl.NumberFormat`, ingen desimaler |
| `formatDecimal(2.64, 1)` | 2,6 | Fast antall desimaler |
| `formatCurrency(16.53743710, 'USD')` | 16,54 USD | To desimaler. Bruk `{ unit: true }` for fire desimaler i enhetspriser. |
| `formatPercent(0.3812)` | 38 % | Andel 0–1, ingen desimaler |
| `formatPoints(-0.031)` | −3,1 pp | Prosentpoeng med fortegn og ekte minus |
| `formatDate('2026-11-01')` | 1. nov. 2026 | Året utelates i inneværende år |
| `formatDateTime(iso)` | 21. nov. 02:14 | Tenantens tidssone |
| `formatRelative(iso)` | i natt, i går, om 3 dager | «i natt» gjelder 00:00–06:00 i dag |
| `formatCount(n, 'answers')` | 1 svar, 412 svar | Flertall fra språkfilen |

- **Lint:** en ESLint-regel i `eslint.config.js` stopper `toFixed(`, `toLocaleString(` og `Intl.` utenfor `format.js` i `resources/js/**`. Unntak krever en kommentar med begrunnelse.
- **Tester:** `tests/js/format.test.mjs` dekker `nb`, `sv` og `en` og negative tall.
- **Eksisterende kall:** U18 erstatter `useDateTime.js` og bytter alle eksisterende kall til `format.js`.
- **Slik U18 er bygget:**
  - `HandleInertiaRequests` deler `tenant: { timezone }` for aktiv tenant (`null` for gjester). `app.js` kaller `configureFormat({ locale, timeZone })` ved første side og hvert besøk. Innstillingene er reaktive, så et språkbytte oppdaterer formaterte verdier.
  - I tillegg til tabellen: `formatTime(iso)`, `compareText(a, b)` (sortering etter språket, erstatter `Intl.Collator`), `intlLocale()` og valgfrie Intl-innstillinger som tredje argument (f.eks. `formatPercent(v, { maximumFractionDigits: 1 })` for grafene). Manglende verdi gir «–».
  - `formatRelative` og `formatCount` henter tekstene fra `lang/*/workspace.php` (`workspace.format.*`). ICU skriver «om 3 døgn» på norsk, derfor brukes ikke `Intl.RelativeTimeFormat`. Mer enn 30 dager unna vises som dato.
  - Lint-regelen heter `ekko/format-through-format-js` og stopper også `toLocaleDateString(` og `toLocaleTimeString(`. Et unntak skrives bare som `// eslint-disable-next-line ekko/format-through-format-js -- begrunnelse`. Regelen `ekko/format-exception-needs-reason` stopper unntak uten begrunnelse, unntak på samme linje eller som blokk, `eslint-disable` uten regelnavn og direktiver som slår av vaktregelen selv, både i script og i Vue-maler. Et direktiv øverst i en fil kan likevel skjule enhver ESLint-rapport, så `tests/js/format-lint.test.mjs` leser i tillegg alle filene i `resources/js` med samme regel (`directiveProblem` i `eslint.config.js`).
  - Datoer i eksisterende sider vises nå i tenantens tidssone og uten år i inneværende år. Referansebildene for sidene med datoer endres derfor.

### 4.3 Datatabell (U18)

Beslutningene i U18 står i ADR 0044 (`docs/decisions/0044-felles-komponenter-for-arbeidsflaten.md`).

**Komponent:** `Components/UI/DataTable.vue`, bygget på `@tanstack/vue-table` og `@tanstack/vue-virtual`. Den er headless, og all stil kommer fra tokens.

```
<DataTable
  :rows="rows" :columns="columns" row-key="id"
  v-model:selection="selectedIds"      // Set<id>; flervalg av når undefined
  :group-by="'topic_id'" :group-label="(key, rows) => ..."   // valgfritt
  :active-row="openId" @open="id => ..."                     // Enter / klikk på primærcelle
  :column-store-key="'setup.prompts'"                         // husker synlige kolonner
  :empty="{ title, body, action }"
  dense
/>
```

**Kolonner:**

- En kolonne har `id`, `header`, `cell` (slot `cell-<id>`), `width` (CSS-grid-spor), `align` (`end` for tall, `tabular-nums`), `sortable`, `hideable` og `primary`.
- Den primære kolonnen åpner raden.

**Utseende (skisse 2 og 3):**

- Tabellen ligger i `rounded-[14px] border border-line` med `overflow-x-auto` og egen `min-width`.
- Overskriftsraden har 11,5 px tekst i `text-shell-muted` og `border-b border-line`.
- Rader har `py-[9px] px-3` og `border-b border-line-soft`.
  - En valgt rad har en svak markering (`bg-row-hover/60`).
  - En åpen rad har `bg-row-hover`.
- Grupperte tabeller har en overskriftsrad per gruppe med antall og pil for sammenfelling.

**Atferd:**

- **Flervalg:** avkrysning per rad og «velg alle synlige» i overskriften. Shift-klikk velger et område.
- **Handlingslinje:** vises når minst én rad er valgt (komponent `BulkBar`, se 4.5).
- **Tastatur:**
  - Piltaster opp og ned flytter fokus.
  - `x` velger og `Enter` åpner.
  - `j` og `k` går til neste og forrige rad når detaljpanelet er åpent.
  - `/` fokuserer søkefeltet.
  - `Esc` lukker panelet eller fjerner valget.
- **Virtualisering:** tabellen virtualiseres over 200 rader. Det er ingen paginering under 2 000 rader.
- **Kolonnevalg:** popover «Kolonner». Valget lagres i `localStorage` under `ekko.table.<key>`, med try/catch.
- **Skjelett og tomme tilstander:** som U14.

**Tester:** `tests/js/data-table.test.mjs` (utvalg, shift-område, tastatur) og en side i `Pages/Dev/Components.vue` med alle varianter. Siden står i sideregisteret.

**Bundle:** måles mot budsjettet i U15, og økningen står i PR-en.

**Slik U18 er bygget:** `@tanstack/vue-table` 9 (med `columnVisibilityFeature`, `rowSortingFeature` og sortert radmodell) eier kolonner, sortering og kolonnevalg. Utvalg, shift-område, gruppering, tastatur og lagring av kolonner ligger som rene funksjoner i `Components/UI/dataTable.js`, som testene kjører i Node. Tabellen bruker `role="grid"` med ett radfokus i tabulatorrekkefølgen (roving tabindex); avkrysningsboksene og primærcellen nås med `x` og `Enter`. Kolonner har i tillegg `accessor` (felt eller funksjon). Gruppeoverskrifter kan tilpasses med sloten `group`, og søkefeltet legges i sloten `toolbar`, som `/` fokuserer. `j` og `k` virker også når fokus står i detaljpanelet. Komponentsiden er `/dev/components` (`dev.components`) og står i sideregisteret som `components` og `components-drawer` (`?open=3`).

### 4.4 Detaljpanel (U18)

Beslutningene i U18 står i ADR 0044 (`docs/decisions/0044-felles-komponenter-for-arbeidsflaten.md`).

**Komponent:** `Components/UI/Drawer.vue`, bygget på Headless UI `Dialog` uten modal bakgrunn på desktop (skisse 2 og 3).

**Utseende:**

- **Desktop (1280 px og bredere):** panelet står som egen kolonne (flex `1 1 360px`, høyst 420 px) til høyre for tabellen, med `bg-shell-surface border-line rounded-2xl p-4`. Tabellen blir smalere og ligger ikke under panelet.
- **Smalere skjermer:** panelet legger seg over innholdet fra høyre (`max-w-[480px]`, full bredde på mobil) med bakgrunn og fokusfelle.

**Atferd:**

- **Hode:** etikett («Prompt · Norge»), lagringsstatus (4.5) og lukkeknapp med `aria-label`.
- **URL:** den åpne raden står i URL-en som `?open=<id>`, med `preserveState` og `replace`. Lenken kan deles, og tilbake lukker panelet.
- **Faner:** panelet kan ha faner (`role="tablist"`), slik prompt-panelet har Resultater, Siste svar og Historikk.

**Avvik i U18:** på desktop er panelet en `<aside>` uten Headless UI `Dialog`. `Dialog` fanger alltid fokus og gjør resten av siden inert, og det ville stoppe tabellen og `j`/`k` ved siden av panelet. Under 1280 px brukes `Dialog` med bakgrunn og fokusfelle som beskrevet. `?open=<id>` styres av `useOpenRow()`: åpning legger til en historikkoppføring (tilbake lukker panelet), mens bytte av rad og lukking erstatter den. Besøkene er klientbesøk i Inertia uten nytt kall til serveren.

### 4.5 Lagring, handlingslinje og angre (U18)

- **`useAutosave(url, { method: 'patch' })`:** gir `save(field, value)` og status per felt: `idle`, `saving`, `saved` eller `error`.
  - Kallet sendes med Inertia `router.patch(..., { preserveState: true, preserveScroll: true, only: [...] })`.
  - Visningen oppdateres før svaret kommer. Ved feil rulles den tilbake, og feilen vises ved feltet.
  - Tekstfelt lagres ved blur eller `Enter`. Valglister og brytere lagres med en gang.
- **`<SaveStatus :state>`:** viser «Lagrer…», «Lagret» med hake i `text-success-text` eller feilen i `text-error-text`. «Lagret» forsvinner etter 2 sekunder.
- **`<BulkBar :count @clear>`** (skisse 2): `rounded-[14px] bg-row-hover border-line`, «*n* valgt», en slot for knapper og «×» som fjerner valget. Linjen står under tabellen og følger med når siden rulles (`sticky bottom-4`).
- **`useUndoToast()`** (skisse 3 og 5): `show(text, undoFn)`.
  - Meldingen er lys (`bg-shell-text text-shell`) med «Angre» i `text-primary-deep` og forsvinner etter 8 sekunder.
  - Bare én melding vises om gangen, og den står i en `aria-live="polite"`-region.
  - Angre kaller den motsatte handlingen på serveren. Det finnes ingen utsatt sletting.
- **Felles lagring** brukes bare for måleplanen (5.4).
- **Slik U18 er bygget:** `useAutosave(url, { method, only, values })` gir `values`, `save`, `status(field)`, `errors`, en samlet `state` for panelhodet og `reset()` når panelet bytter rad. Lagringer til samme felt sendes én om gangen, så serveren alltid får dem i riktig rekkefølge: mens én er på vei, venter den nyeste nye verdien og sendes deretter. Ulike felt lagres samtidig (asynkrone Inertia-besøk). Feiler den siste verdien, rulles feltet tilbake til siste godtatte verdi; et avbrutt besøk ruller ikke tilbake. Valideringsfeil fra Laravel vises ved feltet; andre feil gir «Kunne ikke lagre. Prøv igjen.». Angre-meldingen vises av `Components/UI/UndoToast.vue` i `AppLayout`; mislykket angre erstatter meldingen med en feil.

### 4.6 Kommandopalett med handlinger (U18)

`CommandPalette` (U14) får gruppen «Handlinger» over søketreffene:

- «Ny prompt», «Nytt tema», «Legg til konkurrent», «Nytt marked» og «Nytt faktum»: navigerer til oppsettssiden med `?create=prompt|topic|competitor|market|fact`, og siden åpner opprettelsen.
- «Bytt marked …» og «Bytt merkevare …»: viser en underliste.
- «Åpne innboks» og «Lag rapport nå» (`reports` som i dag).

Handlingene vises etter `workspace.can`. «Lag rapport nå» krever `reports.manage`.

**Slik U18 er bygget:** reglene står i `Components/paletteActions.js` og er testet i `tests/js/workspace-components.test.mjs`. «Nytt faktum» krever `brands.manage` (som `BrandFactPolicy`) og går til `brands.facts.index?create=fact`. Handlinger mot ruter som ikke finnes, vises ikke. Etter U17 deler skallet `workspace`, og rutene `brands.setup.*` og `inbox.index` finnes, så paletten viser handlingene etter `workspace.can`. Gruppen heter «Handlinger»; teambytte står fortsatt under «Hurtigvalg». «Ny prompt» og «Nytt tema» vises, fordi U20 har tatt `brands.setup.prompts` ut av `FORWARDING_ROUTES`. «Legg til konkurrent» vises fra U22, fordi siden åpner `?create=competitor`. «Nytt marked» vises fra U24, fordi `brands.setup.markets` åpner panelet for `?create=market`.

## 5. Skjermer

Alle skjermer har dette hodet: brødsmule i 12 px `text-shell-muted` («Fjordbyrå · Oppsett · Norge»), tittel i 26 px `font-medium tracking-tight` og knapper til høyre, slik skissene viser.

### 5.1 Merkevareoversikt med helsehode og oppstartsliste (skisse 1)

**Rute:** `brands.show`. Siden er dagens `Brands/Show.vue`. U26 legger til helsehodet og oppstartslisten, og U16 retter periode og share of voice.

**Rekkefølge:** hode, filterlinje (U11), helsehode, datamerknad (4.7 i kapittel 7), innsiktslinje (U12), nøkkeltall (U05) og grafer (U05).

**Helsehodet** er tre kort i et rutenett (`repeat(auto-fit, minmax(280px, 1fr))`, `gap-3`). Dataene kommer fra `BrandHealth` (6.5):

1. **Måling:**
   - En linje per aktivt marked, for eksempel «målt i natt 02:14 · neste i natt», fra siste fullførte `run_batches` og neste forfall i måleplanen.
   - Linjen «Motorer: *n* aktive · *svar* svar per måned» kommer fra måleplanens estimat for lagret plan.
   - Lenke: «Åpne måleplanen →».
   - Er det ingen aktive motorer, står «Ingen motorer er aktive» med lenke.
2. **Krever oppmerksomhet:**
   - Høyst tre rader med statuskant (4 px, `rounded`) og lenke til stedet saken løses.
   - Kildene, sortert etter alvor: integrasjon med feil (`error`), manglende pris i planen (`error`), kvote over 80 % (`warning`), ukjente merkevarer som venter (`warning`) og nye tiltak (`warning`). Kvotestatus vises til brukere med tilgang til forbrukssiden.
   - Uten saker står «Alt ser bra ut» i `text-success-text`.
3. **Kom i gang:** oppstartslisten (5.8) med «*x* av 6», fremdriftslinje i `success-text` og «Skjul».

**Slik U26 er bygget:** «Alt ser bra ut» og haken bruker `text-success`. I mørkt tema peker den klassen på `--color-success-text` (`resources/css/app.css`), fordi `#4a7c59` er under 4,5:1 på det mørke skallet. I lyst tema er `#4a7c59` mørkere enn `#5d8a6a` og lesbar på hvitt. Fremdriftslinjen bruker `bg-success`, som er fyllfargen. Tokenet `--color-success-text` ligger utenfor `@theme`, så `text-success-text` er ikke en egen utility. En merkevare uten markeder viser «Legg til et marked» i helsehodet, og den gamle lenken skjules da. Når merkevaren har markeder, står «Legg til marked» igjen som vei til et nytt marked.
   - Valget om å skjule lagres per bruker og merkevare (`user_brand_preferences.checklist_hidden_at`, ny tabell i U25).
   - Når listen er fullført eller skjult, får kort 1 og 2 hver sin halvdel.

**Akseptkriterier (U26):**

- Helsehodet vises på 1440×900 uten at nøkkeltallene går ned under bretten.
- Tallene stemmer med `BrandHealth`.
- En merkevare uten markeder viser «Legg til et marked» i stedet for helsehodet.

### 5.2 Prompts og temaer (skisse 2)

**Rute:** `GET /brands/{brand}/setup/prompts?market=<id>&topic=<id>&open=<promptId>` (`brands.setup.prompts`), siden `Pages/Brands/Setup/Prompts.vue`. Kontrolleren lages i U20 og leser data fra `PromptLibrary` (6.2).

**Marked:**

- URL-en er kilden, som i skallet. Uten `market` vises alle markeder. Siden skriver ikke om URL-en til det første markedet, for da kan ikke «Alle markeder» i omfangsvelgeren bli stående.
- «Alle markeder» gir en markedskolonne og grupperte rader per marked. Da er knappen «Ny prompt» deaktivert, med verktøytipset «Velg et marked».
- «Ny prompt» i kommandopaletten tar med gjeldende marked når ett er valgt, så feltet åpnes med én gang. Uten marked viser siden «Velg et marked for å opprette prompten» og åpner feltet når et marked blir valgt. Siden skriver fortsatt ikke om URL-en til det første markedet.

**Hode (høyre side):**

- «*n* av *grense* prompts i pakken»: aktive prompts i markedet mot `plans.max_prompts_per_market`. Teksten skjules når grensen er null.
- Knappene «Importer CSV», «Foreslå prompts» og «Ny prompt» (primær).
- Ved taket er «Ny prompt» deaktivert, og verktøytipset forklarer grensen.

**Temakolonnen** (venstre, flex `1 1 200px`):

- Øverst står «Alle temaer», deretter temaene sortert etter navn, og «Uten tema» nederst.
- Hver rad viser navn, en linje med markedene temaet har prompts i (`·`-separert), synlighet for valgt periode (28 dager) og antall prompts i markedet.
- Klikk filtrerer tabellen med `?topic=`.
- «+ Nytt tema» åpner et felt på stedet. `Enter` oppretter temaet og velger det.
- **Gi nytt navn:** dobbeltklikk eller menyen «…» ved temaet.
- **Slå sammen:** dra ett tema over et annet, eller velg «Slå sammen med …» i menyen. Bekreftelsen sier «Flytter *n* prompts fra A til B og sletter A».
- **Slett:** bare tomme temaer kan slettes. For andre er valget deaktivert, med forklaring.
- Teksten «Dra et tema over et annet for å slå dem sammen» står under listen.

**Verktøylinje over tabellen:**

- Søk i teksten.
- Brikkene Intensjon, Trakttrinn og Kjerne (flervalg).
- Status med valgene Aktive (standard), Inaktive, Arkiverte og Alle.
- «Kolonner».

**Tabell (DataTable):**

| Kolonne | Innhold | Standard |
|---|---|---|
| Valg | avkrysning | synlig |
| Prompt | tekst (13,5 px, inaktiv i `text-shell-muted`) og under den «Intensjon · Trakttrinn» (12 px) | synlig, primær |
| Tema | temanavn | synlig, skjules når et tema er valgt |
| Marked | marked | bare ved «Alle markeder» |
| Kjerne | pillen «Kjerne» (`bg-data-seq-1 text-shell-accent`) | synlig |
| Motorer | én celle per aktiv motor i markedet (28×22 px). Fargen er `--data-seq-*` etter synlighet siste 28 dager, tallet er prosent, og «–» betyr ikke målt. Cellen har `aria-label` «ChatGPT: nevnt i 71 % av svarene». | synlig |
| Sist målt | relativ tid | skjult |
| Aktiv | bryter | skjult |

- Under radene står «+ Ny prompt i *marked* · *tema*» med tasten `N`. Klikk eller `N` legger en redigerbar rad øverst. `Enter` lagrer med intensjon `category`, trakttrinn `awareness`, prioritet `standard` og aktiv. Felt kan endres i panelet etterpå.

**Handlingslinje for valgte rader:**

| Knapp | Handling (6.2 `bulk`) |
|---|---|
| Flytt til tema ▾ | `move_topic` med tema eller «Uten tema» |
| Sett som kjerne / Fjern kjerne | `set_priority` |
| Endre intensjon ▾ | `set_intent` |
| Endre trakttrinn ▾ | `set_funnel` (i menyen «…» ved smal skjerm) |
| Aktiver / Deaktiver | `activate` / `deactivate` |
| Arkiver | `archive` (`text-error-text`) |

Hver handling gir en angre-melding, for eksempel «20 prompts flyttet til Shopify-migrering · Angre». Aktivering over pakkens grense avvises samlet med feilen fra serveren. Ingenting lagres da.

**Detaljpanel (Drawer):**

- **Hode:** «Prompt · *marked*» og lagringsstatus.
- **Felt med automatisk lagring:** tekst (textarea, lagres ved blur), tema, intensjon, trakttrinn, kjerne og aktiv.
  - En duplikat tekst gir feilen fra serveren ved feltet.
  - Ser teksten ut til å inneholde persondata (`PersonalData`), vises en advarsel ved feltet.
- **Faner:**
  - **Resultater:** én rad per motor med navn, sju prikker for de siste sju dagene (fylt `bg-primary` når merkevaren ble nevnt) og «nevnt *x* av 7 dager · plass *p*». En motor som ikke måler prompten, viser «Ikke målt», med årsaken «bare kjerne» når prompten ikke er kjerne.
  - **Siste svar:** siste svar med uthevede navn (`AnswerText`). Egen merkevare har `bg-data-seq-2`, konkurrenter `bg-line-soft`. Svaret har motor, tidspunkt og lenken «Åpne promptsiden →» (`brands.prompts.show`).
  - **Historikk:** endringer fra `activity_log` (hvem, hva og når), bare feltnavn og verdier.
- **Nederst:** «Slett» for prompts uten svar, ellers «Arkiver».

**Foreslå prompts:**

- Knappen åpner et panel: «Foreslå prompts for *tema* i *marked*». Antall kan være 5 eller 10.
- Forslagene vises med tekst, intensjon, trakttrinn og avkrysning, og alle er valgt.
- «Legg til valgte» oppretter dem i ett kall (`bulk` med `create`) og viser «*n* prompts lagt til · Angre».
- Kostnaden vises før generering: «Bruker 1 modellkall fra kvoten».

**Import:**

- Panelet har filvelger og innliming.
- «Forhåndsvis» kaller `import/preview`. Resultatet vises i tre seksjoner: Nye (*n*), Endret (*n*, med felt som endres, før og etter) og Uendret (*n*, sammenfelt). Feil vises per linje.
- «Importer *n* endringer» kaller dagens `brands.prompts.import`.
- «Eksporter CSV» ligger i menyen «…».

**Tomme tilstander:**

- Markedet har ingen prompts: «Ingen prompts i *marked* ennå», med «Foreslå prompts» og «Importer CSV».
- Tema uten prompts: «Ingen prompts i dette temaet».

**Analysesiden `brands.prompts.index`** beholder heatmap og tabell (U06). Redigering i cellene og CSV fjernes der og erstattes av lenken «Rediger i Oppsett → Prompts og temaer». U20 gjør dette.

**Akseptkriterier (U20):**

- Et nytt tema med tre nye prompts kan lages uten å forlate siden.
- 20 prompts kan flyttes til et annet tema og settes som kjerne i to handlinger, og hver handling kan angres.
- En import viser nye, endrede og uendrede rader før lagring og lagrer ingenting før bekreftelse.
- Pakkens grense håndheves både i grensesnittet og på serveren.
- En leser ser siden uten endringsknapper. Serveren avviser endringer fra en leser med 403.
- Tastatur: `N` gir ny prompt, `x` og `Enter` velger og åpner, og `j` og `k` blar i panelet.

### 5.3 Konkurrenter og merkevarer (skisse 3)

**Rute:** `GET /brands/{brand}/setup/competitors?tab=pending|competitors|other|ignored&market=<id>&open=<key>` (`brands.setup.competitors`), siden `Pages/Brands/Setup/Competitors.vue`. Data kommer fra `EntityQueue` og `CompetitorLibrary` (6.3).

**Faner:**

| Fane | Innhold |
|---|---|
| Til behandling | navn uten beslutning, med tall i rød pille |
| Konkurrenter | aktive og inaktive konkurrenter |
| Andre aktører | navn med beslutningen `other` |
| Ignorert | navn med beslutningen `ignored` |

**Til behandling:**

- **Ingress:** «Navn fra svarene som ikke er registrert. Sortert etter andel av svarene siste 30 dager. Varianter med samme normaliserte navn er slått sammen.»
- **Taster** (til høyre): `1` Konkurrent, `2` Annen aktør, `3` Alias, `4` Ignorer, og `J`/`K` for neste og forrige.
- **Tabell (DataTable):**

  | Kolonne | Innhold |
  |---|---|
  | Valg | avkrysning |
  | Navn | visningsnavn (13,5 px `font-medium`), forslagspille («Forslag: annen aktør»), og under det «Også: *varianter* · *markeder* · *n* motorer» |
  | Andel av svar | «18 % · 74 svar» og en linje (4 px, `bg-medium-beige`, bredde lik andelen × 3 med tak på 100 %) |
  | Sist sett | relativ dato |
  | Behandle | knappene Konkurrent, Annen aktør, Alias av … og Ignorer (12 px piller). Knappen som svarer til forslaget, har kant i `border-medium-beige`. |

- **Beslutning:**
  - Raden får `opacity-60` og «→ *beslutning*» i `text-success-text` med «Angre». Knappene og avkrysningen forsvinner.
  - Raden blir stående til siden lastes på nytt, slik at brukeren ser hva som er gjort.
  - Tallet på fanen synker med en gang.
  - Angre-meldingen er for eksempel «Havnelys Studio er lagt til som konkurrent. · Angre».
- **Alias av …** åpner en liten meny med egen merkevare og de aktive konkurrentene, med søk. Et forslag `alias` står øverst.
- **Handlingslinjen** for valgte rader har Konkurrent, Annen aktør og Ignorer. Alias kan bare velges én og én.
- **Bunnlinje:** «Viser *x* av *n* · navn som er sett i færre enn 2 svar, vises ikke».

**Detaljpanel (alltid synlig på desktop, skisse 3 til høyre):**

- Etiketten «Ukjent merkevare», navnet (20 px) og «Funnet i *n* svar (*x* %) siste 30 dager · *markeder* · også skrevet *varianter*».
- **«Slik står navnet i svarene»:** tre utdrag fra de nyeste svarene. Hvert utdrag har 80 tegn før og etter navnet, med navnet uthevet (`bg-data-seq-2`), og kilden «*Motor* · *marked* · *dato*» i `figcaption`.
- **Valgliste:** «Legg til som alias av» med egen merkevare og konkurrentene.
- **Forklaring:** «Annen aktør teller ikke i share of voice. Ignorerte navn kommer ikke tilbake i køen, i varsler eller i tiltak.»

**Konkurrenter (fane):**

- Tabellen har dra-håndtak, fargeprikk (`--data-cat-n` etter plass blant aktive), navn, aktiv-bryter, alias (antall), domener (antall), synlighet 28 dager og endring.
- **Rekkefølge:** dra og slipp endrer plassen og dermed fargen i alle grafer.
- **Detaljpanel:**
  - Navn (automatisk lagring) og aktiv.
  - **Alias:** pille per alias med valg av matchtype (eksakt ord, uten skille på store og små bokstaver, regex) og ×. Feltet «Legg til alias» har matchtest (5.5).
  - **Domener:** piller.
  - **Arkiver:** dagens logiske sletting. Samme navn kan opprettes igjen senere.
- «Legg til konkurrent» i hodet åpner panelet med tomt navn.

**Andre aktører og Ignorert:** tabell med navn, varianter, hvem som bestemte og når, og «Angre». Angre sender navnet tilbake til køen. «Gjør til konkurrent» finnes under Andre aktører.

**Analysesiden `brands.competitors.index`:** forslagsveggen fjernes. I stedet står «*n* ukjente merkevarer venter på behandling» med lenke til fanen Til behandling når tallet er over 0. Rangering, sentiment og tabell er som U07. U22 gjør dette.

**Akseptkriterier (U22):**

- 50 navn kan behandles på under 2 minutter med tastatur og flervalg.
- Et ignorert navn kommer ikke tilbake i køen, i `new_competitor`-varsler eller i `new_competitor`-tiltak.
- Et navn lagt som alias vises under konkurrenten i grafene etter neste aggregering (6.3).
- Endret rekkefølge gir samme farge for konkurrenten på dashboard, konkurrentside, kildeside og i rapport.

**Slik U22 er bygget:**

- **Filer:** `SetupCompetitorController` (siden, tar over `brands.setup.competitors` fra videresendingen), `Pages/Brands/Setup/Competitors.vue`, `Components/Setup/` (`EntityPanel`, `CompetitorPanel`, `AliasPicker` og de rene reglene i `entityQueue.js`, testet i `tests/js/competitor-setup.test.mjs`). Skrivingen går til JSON-rutene fra U21. «Legg til konkurrent» bruker `brands.competitors.store`. `DataTable` har fått `row-selectable`, så en behandlet rad mister avkrysningen.
- **Tall uten sidelasting:** tallet på «Til behandling» regnes lokalt (køen minus behandlede navn) og endres med en gang. Sidemenyen og de andre fanene (`competitors`, `others`, `ignored`, `targets`, `workspace`) hentes med en delvis Inertia-omlasting 200 ms etter siste endring. Selve køen lastes ikke på nytt, så behandlede rader blir stående.
- **Tastatur:** `1`–`4` gjelder de valgte radene, ellers den åpne raden. Etter en beslutning åpnes og fokuseres neste ubehandlede rad (`cursor` i siden, så raske tastetrykk ikke treffer en rad som allerede er behandlet). `3` åpner `AliasPicker` for én rad. Siden bruker `x`, `Enter`, `j`, `k` og `/` fra `DataTable`.
- **Rekkefølge:** dra og slipp bruker HTML5 drag and drop på håndtaket, og `Alt` med piltastene flytter raden for tastaturbrukere (med `aria-live`-melding). Inaktive konkurrenter beholder plassen sin (`reorder` får bare de aktive).
- **Avvik og tillegg:**
  - Detaljpanelet viser første ubehandlede rad når ingen rad er åpnet, på skjermer fra 1280 px. På smalere skjermer åpnes det bare for en rad brukeren har åpnet.
  - Matchtesten under «Legg til alias» (5.5) er ikke med. Den trenger `aliases/test` fra U23 (6.4). Domener normaliseres i nettleseren (uten protokoll, sti og `www`) før de sendes.
  - Konkurrentpanelet lagrer med egne kall og `SaveStatus`, ikke `useAutosave`, fordi rutene svarer med JSON og ikke med en Inertia-respons.
  - Under «Andre aktører» og «Ignorert» finnes «Angre» bare i 24 timer (6.3). Etter det står teksten «Kan ikke angres etter 24 timer». «Gjør til konkurrent» under Andre aktører angrer først beslutningen og tar en ny, og etter 24 timer legger den til konkurrenten med `brands.competitors.store`, mens beslutningen `other` blir stående.
  - Andel i «Andre aktører» regnes over de siste 30 dagene (`ekko.entities.queue_days`) i markedene på siden. Synlighet og endring i konkurrenttabellen er over 28 dager mot de 28 foregående, i samme markeder.
  - Analysesiden beholder tabellen «Alias og domener». Bare forslagsveggen er byttet ut med lenken. Tallet i lenken er antall navn i køen for markedene i filteret, uten motor- og temafilter, slik at det stemmer med siden den lenker til.
  - Angre-meldingen gjelder siste beslutning. Eldre beslutninger angres med «Angre» på raden.
  - Tallet på «Til behandling» står i `bg-primary-deep` (tokenet for den røde pillen). Bunnlinjen viser «Viser *x* av *n*» der *n* er alle ukjente navn i vinduet, også de under to svar (`queueTotal`).
  - Flervalg sendes til serveren i grupper på høyst 100 navn. Dra og slipp flytter bare aktive konkurrenter (som `reorder` gjør), og håndtaket er av for inaktive rader.
  - «Angre» og «Gjør til konkurrent» under de andre fanene laster køen på nytt, slik at navnet kommer tilbake. Behandlede rader i «Til behandling» forsvinner da før siden er lastet på nytt.
  - Feiler «Gjør til konkurrent» etter at «annen aktør» er angret, gjøres den forrige beslutningen på nytt, og feilen vises på siden.
- **Tester:** `tests/Feature/Entities/SetupCompetitorPageTest.php`, `tests/js/competitor-setup.test.mjs` og `tests/Browser/triage.spec.mjs` (50 syntetiske navn på under to minutter med tastatur og flervalg, tall på fane og i sidemenyen uten sidelasting, angre, dra og slipp, konkurrentpanelet). Siden står i sideregisteret som `setup-competitors`.

### 5.4 Markeder og måleplan (skisse 4)

**Rute:** `GET /brands/{brand}/setup/markets` (`brands.setup.markets`), siden `Pages/Brands/Setup/Markets.vue`. Planen bruker dagens API (`brands.plan.show`, `estimate` og `update`, T53–T55). Logikken i `measurementPlan.js` og `usePlanEstimate.js` gjenbrukes.

**Markedstabell:**

- **Kolonner:**
  - **Marked:** navn og «*språk* · *sted*».
  - **Status:** Aktiv i `success-text`, Inaktiv i `muted`.
  - **Prompts:** «46 · 12 kjerne».
  - **Plan:** «Arver merkevarens plan» eller «Egne valg».
  - **Neste måling:** neste måledato.
- **Detaljpanel:** sted (automatisk lagring), aktiv-bryter med forklaring («Inaktive markeder måles ikke») og lenke til prompts i markedet.
- **«Nytt marked»** åpner et panel med land (valgliste ISO 3166-1), språk (BCP 47, standard for landet), sted (valgfritt) og to valg:
  - **«Kopier prompts fra …»:** markeder med samme språk. Kallet `markets.store` med `copy_from` oppretter markedet og kopierer aktive prompts innenfor pakkens grense.
  - **«Foreslå prompts»:** sender til dagens veiviser for nytt marked (`brands.markets.create`).

  Nye markeder arver merkevarens plan.

**Planmatrise:**

- **Oppbygging:** rader er motorer i pakkens rekkefølge. Kolonnene er «Merkevarens standard» pluss ett aktivt marked per kolonne. Inaktive markeder vises ikke.
- **Kolonnehode for et marked:** navn og en segmentbryter «Arver | Egne valg» (`aria-pressed`). Bytte til «Egne valg» kopierer markedets gjeldende oppsett. Bytte til «Arver» tar i bruk standarden (spec 7.6).
- **Cellene** er knapper (`aria-haspopup`) med tre linjer:
  1. Hovedlinjen er «*intervall* · *utvalg*».
  2. Andre linje er gjentakelser eller «+ kjerne *intervall* *n*×» når det finnes en kjerneregel.
  3. Tredje linje er «*svar* svar/mnd» fra estimatet, med « · arvet» eller « · endret» der det gjelder.
- **Tilstander for cellene:**
  - Arvede celler er dempet (`text-shell-muted`, `bg-shell-surface`) og kan ikke redigeres før markedet bytter til egne valg.
  - Motorer utenfor pakken viser «Ikke i pakken» og er sperret. Raden har forklaringen «Krever større pakke».
  - En motor som er av, viser «Av».
  - Den valgte cellen har ramme (2 px `primary`, inn mot cellen).
- **Regelredigering** (panel til høyre, skisse 4):
  - «Redigerer *motor* · *marked*».
  - Intervall som piller: Daglig, Ukentlig, Hver 14. dag, Hver 3. uke, Månedlig og Av. Valg over pakketaket er sperret, med «Daglig er sperret av pakken (tak: ukentlig)».
  - Utvalg (Alle spørsmål eller Bare kjerne) og gjentakelser (1 til pakkens maks).
  - **Kjerneregel:** «+ Legg til kjerneregel», eller den eksisterende regelen med «Fjern».
  - Nederst: «Denne motoren i *marked*: *svar* svar per måned» og «Neste måling: *dato* · «*eksempelspørsmål*»» (`rules[].next_due_on`).
- **Lagringslinjen** står fast nederst (`sticky bottom-0`, `bg-shell-surface border-t border-line`):
  - Den viser ulagrede endringer (prikk i `warning`), svar per måned med endring mot lagret plan, kvotebelastende kall, kostnad per måned og «Igjen av kvoten i *måned*».
  - Status er «Innenfor kvote og budsjett» i `success-text`, eller `issues` fra estimatet i `warning` eller `error`.
  - Knappene er «Forkast» og «Lagre plan».
  - Uten endringer viser linjen lagret plan og ingen knapper.
- **Feil og navigasjon:** valideringsfeil fra `PUT` vises ved cellen. Forsøker brukeren å forlate siden med ulagrede endringer, spør Inertia om bekreftelse.

**Akseptkriterier (U24):**

- 2 markeder og 6 motorer kan leses uten å rulle på 1440×900.
- Estimatet oppdateres innen 1 sekund etter en endring, med debounce på 300 ms.
- Regneeksemplet i spec 7.6 gir samme tall som før.
- Veiviseren bruker fortsatt samme API.

**Slik U24 er bygget:**

- **Sider:** `SetupMarketController`, `SetupIdentityController` og `SetupIntegrationController` tar over de tre rutene. `GET brands.settings` sender til identitet. `PUT brands.settings.update` og `Brands/Settings.vue` blir stående til U27.
- **Plan:** matrisen bruker `measurementPlan.js` og `usePlanEstimate`. Kolonnene er merkevarestandard pluss hvert aktivt marked. Standardkolonnen viser reglene, ikke svar per måned: tallet `engines` på estimatet er summen for alle markeder. Hver markedskolonne viser sine egne svar. Totalen står i lagringslinjen. Estimatet venter 300 ms etter en endring, og lastes på nytt når et marked slås av eller på. Arvede celler kan ikke redigeres. Ulagret navigasjon spør om bekreftelse. Lagring av sted og aktiv-bryter beholder utkastet.
- **Identitet:** alias-testen kalles mens feltet skrives (500 ms) mot `brands.aliases.test`. Svaret logges ikke.
- **Avvik:** farlig sone kan sette merkevaren på pause (`active` eller `paused`). U23-ruten godtar ikke arkivering, så siden sier at arkivering ikke er tilgjengelig ennå.

### 5.5 Identitet (ingen skisse, samme mønster som skisse 2 og 4)

**Rute:** `brands.setup.identity`, siden `Pages/Brands/Setup/Identity.vue`.

- **Navn og hoveddomene:** felt med automatisk lagring.
- **Alias:**
  - En liste med alias, matchtype (valgliste) og ×.
  - Feltet «Legg til alias» med matchtype og knappen «Test».
  - **Matchtest:** mens brukeren skriver (debounce 500 ms) kalles `aliases/test` (6.4). Resultatet vises under feltet: «Ville gitt *n* treff i *m* svar siste 30 dager», tre utdrag med uthevet treff, og eventuelle konflikter i `warning` («12 av treffene er i dag Brygge Digital»).
  - Ugyldig regex gir feilen fra serveren.
- **Domener:** liste med domene, bryteren «Med underdomener» og antall siteringer siste 30 dager. Feltet «Legg til domene» normaliserer domenet (uten protokoll og sti).
- **Farlig sone:** sett merkevaren på pause (`status = paused`) eller arkiver den, med bekreftelse der brukeren skriver navnet. Gjelder bare `brands.manage`.

### 5.6 Innboks (skisse 5)

**Rute:** `GET /inbox?brand=<id>&type=<type>&handled=0|1` (`inbox.index`), siden `Pages/Inbox/Index.vue`. Data kommer fra `InboxQuery` (6.5). Omfangsvelgeren gjelder: valgt merkevare og valgt marked filtrerer.

**Oppbygging:**

- **Hode:** «Innboks *n* venter», filteret «Alle merkevarer ▾» og bryteren «Vis behandlet».
- **Fanene** Alle, Varsler, Nye merkevarer, Tiltak, Påstander og Integrasjoner, med antall. Valgt fane har understrek i `primary`.
- **Grupper per lokal dag:** «I dag», «I går», og deretter ukedag med dato.
- **Rad** (`article`, `rounded-[14px] bg-shell-surface border-line-soft`):
  - statuskant (4 px)
  - typeetikett (11,5 px)
  - tittel (14 px `text-shell-fg`)
  - detaljlinje (12,5 px `muted`)
  - knapper til høyre: primærhandling i `bg-primary-deep`, resten som rammeknapper

| Type | Statuskant | Tittel (eksempel) | Knapper |
|---|---|---|---|
| Varsel `visibility_drop` | `warning` | Synligheten i Gemini falt 11 prosentpoeng i Sverige uke mot uke | **Kvitter**, Åpne |
| Varsel `competitor_takeover` | `warning` | Brygge Digital tok førsteplassen på kjerneprompten «…» | **Kvitter**, Åpne |
| Varsel `citation_lost` | `warning` | fjordbyra.no er ikke lenger sitert på «…» i Claude Haiku | **Kvitter**, Åpne |
| Varsel `model_change` (samlet per dag) | `marker-model` | Ny modell i ChatGPT, Gemini og Claude Haiku | **Kvitter alle** |
| Varsel `quota` | `warning`, ved 100 % `error` | Forbruket har nådd 80 % av månedskvoten | **Kvitter**, Åpne forbruk |
| Ny merkevare (kø, 6.3) | `medium-beige` | Havnelys Studio nevnes i 18 % av svarene i Norge siste 7 dager | **forslaget**, så de andre av Konkurrent, Annen aktør og Ignorer |
| Nytt tiltak (`status = new`) | `marker-action` | Få omtale på netthandelsguiden.no | **Planlegg**, Avvis |
| Motsagt påstand uten tiltak | `error` | ChatGPT oppgir at Fjordbyrå ble grunnlagt i 2015. Faktum: 2009. | **Lag tiltak**, Åpne i faktasjekk |
| Integrasjonsfeil | `error` | GA4-synken har feilet to dager på rad | **Åpne integrasjonen** |

- **Lesbar tekst:** tittel og detalj lages på serveren fra språknøkler (6.5). Interne nøkler vises aldri. Varsler bruker `AlertPresenter`.
- **Gruppering:** varsler av samme type, merkevare og dag med mer enn to rader slås sammen («3 varsler om synlighetsfall i Sverige»). En sammenslått rad har «Vis alle» og «Kvitter alle».
- **Behandling:** en handling fjerner raden. Tallet i sidemenyen og på fanen oppdateres (Inertia `only: ['items', 'counts', 'workspace']`).
- **Slik U26 er bygget:** Kvittering og enhetsvedtak kan angres, fordi de har egne ruter. Plan, avvis og «Lag tiltak» kan ikke angres. En bruker kan ikke sette et tiltak tilbake til `new` (spec 11.3, `ActionStatus::userTargets`), og et tiltak som lages fra en motsagt påstand blir stående til brukeren behandler det. Optimistisk skjuling nullstilles når filteret endres, så «Vis behandlet» viser raden uten ny sidelasting.
- **Tom innboks:** «Ingenting venter her. Nye varsler, merkevarer og tiltak dukker opp etter neste måling.»
- **Dagens varselside:** `/alerts` sender til `/inbox?type=alerts`. Varselregler ligger i 5.7.

**Akseptkriterier (U26):**

- Antallet i sidemenyen er det samme som summen på fanen «Alle».
- Kvittering og enhetsvedtak fjerner raden uten ny sidelasting og kan angres. Plan, avvis og «Lag tiltak» fjerner raden uten angre (spec 11.3).
- Ingen rad viser interne nøkler (automatisk test som leter etter `market:`, `prompt:` og heksadesimale hasher).

### 5.7 Varsler og rapporter per merkevare

**Rute:** `brands.setup.notifications`, siden `Pages/Brands/Setup/Notifications.vue`.

- **Varselregler:** én rad per varseltype (spec 11.1) med bryter, terskel (tallfelt med enhet), kanaler (Slack, e-post, innboks som avkrysning) og en forklaring generert fra terskelen, for eksempel «Varsle når synligheten faller mer enn 10 prosentpoeng uke mot uke».
  - Lagres per felt.
  - `quota` og `integration_error` gjelder hele tenanten og vises med merknaden «Gjelder hele Fjordbyrå AS».
- **Rapportplaner:** liste med kadens, mottakere (e-post som piller), språk og profil (tenant eller partner) og «Send en nå til meg». Detaljpanel for redigering, og «Ny rapportplan».
- **Rapporthistorikk:** periode, sendt, lenker til nettversjon og PDF, og delbare lenker (T46) med opprett og trekk tilbake.
- **Dagens `/reports`** blir arkivet på tvers av merkevarer. Opprettelse og redigering av planer skjer her.

### 5.8 Integrasjoner

**Rute:** `brands.setup.integrations`, siden `Pages/Brands/Setup/Integrations.vue`. Siden har ett kort per integrasjonstype (ClickHouse og GA4):

- status (OK, Feiler siden *dato*, Ikke koblet til)
- siste synk
- felt fra dagens innstillinger
- «Lagre»
- for ClickHouse: dagens opplasting av botlogg

Felles lagring per kort er riktig her, fordi oppsettet må valideres samlet.

### 5.9 Oppstartsliste

Den vises i helsehodet (5.1). Punktene krysses av automatisk fra data i `BrandHealth`:

1. Marked med aktiv måleplan: minst ett aktivt marked med minst én aktiv regel.
2. Minst 20 aktive prompts per aktivt marked, hvorav minst 5 kjerne.
3. Behandle ukjente merkevarer: køen har vært tom minst én gang. Det lagres som `brands.entities_reviewed_at`, satt når køen blir tom etter en beslutning.
4. Minst 3 aktive fakta.
5. Minst én rapportmottaker.
6. ClickHouse eller GA4 koblet til (valgfritt). Punktet vises med «valgfritt» og teller ikke for «fullført».

## 6. Backend

### 6.1 Ruter som lages av U17

Disse rutene viser sider. Kontrolleren for hver side lages av UI-oppgaven som eier siden. U17 lager rutene som videresending (4.1).

| Metode og sti | Navn | Side |
|---|---|---|
| `GET /brands/{brand}/setup/prompts` | `brands.setup.prompts` | U20 |
| `GET /brands/{brand}/setup/competitors` | `brands.setup.competitors` | U22 |
| `GET /brands/{brand}/setup/markets` | `brands.setup.markets` | U24 |
| `GET /brands/{brand}/setup/identity` | `brands.setup.identity` | U24 |
| `GET /brands/{brand}/setup/integrations` | `brands.setup.integrations` | U24 |
| `GET /brands/{brand}/setup/notifications` | `brands.setup.notifications` | U26 |
| `GET /inbox` | `inbox.index` | U26. Siden er innboksen. `/alerts` sender til `/inbox?type=alerts`. |

`brands.settings` sender videre til `brands.setup.identity` når U24 er merget. U27 fjerner `Brands/Settings.vue` og `BrandSettingsController::update`.

### 6.2 Temaer og prompts (U19)

**Datamodell:**

- `prompts.archived_at` (nullable timestamptz) med indeks `(market_id, archived_at)`.
  - Arkivering setter `is_active = false` og `archived_at = now()`. Gjenoppretting nullstiller `archived_at` og lar `is_active` være `false`.
  - Arkiverte prompts er utenfor CSV-eksport, API-lister og MCP-lister som standard. Parameteren `include_archived` tar dem med.
  - `PromptPopulation` teller allerede bare aktive prompts.
- `topics.cross_market_key` settes for nye temaer til en slug av navnet, unik per merkevare (med `-2`, `-3` ved kollisjon), slik at veiviseren kan foreslå prompts for temaet i et nytt marked.
  - Endres et navn, endres ikke nøkkelen.
  - Ved sammenslåing beholdes nøkkelen til temaet det slås sammen inn i. Tiltak og prompts flyttes til måltemaet i samme transaksjon. Eksisterende tiltak beholder ID, historikk og opprinnelig fingeravtrykk.

**Tjenester:**

- **`App\Prompts\PromptLibrary`:** én kilde for skriving fra web, API og MCP.
  - `create(Market, array): Prompt`: duplikatkontroll (`text_hash`), pakkens grense ved aktiv, temaet må tilhøre merkevaren, persondatavarsel.
  - `update(Prompt, array)`
  - `bulk(Brand, array $ids, string $action, mixed $value): BulkResult`
  - `delete(Prompt)`: bare uten `runs`, ellers `PromptHasRuns`.

  `Api\PromptController`, `BrandPromptController::update` og MCP `UpsertPrompts` skrives om til å bruke tjenesten. Oppførselen endres ikke, og eksisterende tester skal være grønne.
- **`App\Prompts\TopicLibrary`:** `create`, `rename`, `merge(Topic $from, Topic $into)` (flytter prompts og sletter `from` i én transaksjon) og `delete` (bare tomme temaer, ellers `TopicNotEmpty`).
- **`App\Prompts\PromptLibrary::payload(Brand, ?Market, array $filters): array`:** leverer temaer med antall, markeder og synlighet, og prompts med felter, synlighet per motor siste 28 dager og sist målt. Siden 5.2 trenger dette. Synligheten kommer fra `MetricsService` som i U06.
- **`App\Prompts\PromptSuggester::forTopic(Topic, Market, int $count): array`:**
  - bruker `OnboardingModelClient` med en ny metode i `OnboardingPromptBuilder::topic(...)`
  - samme sikkerhetsregler og parser (`ProposalParser`)
  - reserverer og bokfører som `usage_events.kind = onboarding`
  - teller mot kvoten
  - Forslag med samme `text_hash` som en eksisterende prompt fjernes.

**Ruter** (web, sesjon). Svaret er redirect tilbake med flash der ikke annet står.

| Metode og sti | Navn | Tillatelse | Request | Svar og feil |
|---|---|---|---|---|
| `POST /brands/{brand}/topics` | `brands.topics.store` | `prompts.manage` | `name` (1–100) | 422 ved navn som finnes |
| `PATCH /brands/{brand}/topics/{topic}` | `brands.topics.update` | `prompts.manage` | `name` | |
| `POST /brands/{brand}/topics/{topic}/merge` | `brands.topics.merge` | `prompts.manage` | `into` (tema-ID i samme merkevare, ikke samme tema) | |
| `DELETE /brands/{brand}/topics/{topic}` | `brands.topics.destroy` | `prompts.manage` | – | 422 `topic_not_empty` |
| `POST /brands/{brand}/markets/{market}/prompts` | `brands.prompts.store` | `prompts.manage` | `text`, `intent`, `funnel_stage`, `priority?`, `topic_id?`, `is_active?` | 422 ved duplikat eller grense |
| `DELETE /brands/{brand}/prompts/{prompt}` | `brands.prompts.destroy` | `prompts.manage` | – | 422 `prompt_has_runs` |
| `POST /brands/{brand}/prompts/bulk` | `brands.prompts.bulk` | `prompts.manage` | `ids[]` (1–500, samme merkevare), `action`, `value?`. For `create`: `market_id` og `prompts[]` (høyst 25) | JSON `{ affected, undo: { action, ids, value } }` |
| `POST /brands/{brand}/prompts/import/preview` | `brands.prompts.import.preview` | `prompts.manage` | `file` eller `csv` som `import` | JSON `{ created: [...], changed: [{ id, fields: { felt: [før, etter] } }], unchanged: n, errors: [{ line, message }] }`. Skriver ingenting. |
| `POST /brands/{brand}/topics/{topic}/suggestions` | `brands.topics.suggestions` | `prompts.manage` | `market_id`, `count` (5 eller 10) | JSON `{ suggestions: [{ text, intent, funnel_stage, priority }] }`. Begrenset til 10 per minutt per bruker. 429 ved kvote. |

**Handlinger i `bulk`:** `move_topic` (verdi er tema-ID eller `null`), `set_priority`, `set_intent`, `set_funnel`, `activate`, `deactivate`, `archive`, `unarchive` og `create`.

- Alt skjer i én transaksjon og med samme låserekkefølge som i dag: marked, så prompt.
- `activate` kontrollerer grensen for hvert berørt marked. Overskridelse avviser hele kallet.
- `undo` i svaret beskriver det motsatte kallet. For `move_topic` grupperes ID-ene per forrige tema. Send `undo`-objektet tilbake til samme bulk-rute. Det inneholder handlingen `undo`, berørte ID-er og en kryptert verdi som binder de grupperte før- og etterverdiene til tenanten og merkevaren. Gjenoppretting avviser felt som er endret i mellomtiden. Angret opprettelse bruker samme slettesperre som websletting. Ingen endrede rader gir `undo: null`.

**Andre krav:**

- **CSV:** En tom celle eller manglende kolonne for tema, intensjon, trakttrinn, prioritet eller aktiv-status beholder feltets verdi på eksisterende prompts. Nye rader får importens standardverdier. Forhåndsvisning og import bruker samme regler.
- **API og MCP:** REST (`api.php`) og MCP (`UpsertPrompts`) får `archived` i ressursen og `include_archived` i lister. `docs/api.yaml` oppdateres. Eksisterende REST DELETE beholder kaskadeslettingen fra spec 15.1. Sletting gjennom den nye webruten avvises når det finnes kjøringer. Det kommer ingen nye MCP-verktøy.
- **Tester:**
  - Feature-tester for alle ruter, også tilgang per rolle.
  - Grensen i `bulk` og `create`.
  - `merge` (prompts flyttes, nøkkelen beholdes).
  - `destroy` med og uten svar.
  - Diff i forhåndsvisningen.
  - Forslag med falsk modellklient (`OnboardingFakes`).
  - Isolasjonsregisteret.

### 6.3 Beslutninger om ukjente merkevarer og konkurrenter (U21, ADR 0043)

ADR 0043 «Beslutninger om ukjente merkevarer» skal beskrive valgene under.

**Navnenøkkel:** `App\Detection\EntityName::key(string): string`.

1. Unicode NFKC og små bokstaver.
2. Innhold i parentes fjernes.
3. Juridiske endelser som eget ord på slutten fjernes: `as`, `asa`, `ab`, `aps`, `oy`, `gmbh`, `ltd`, `inc`, `llc`, `bv`.
4. Tegnsetting blir mellomrom, og mellomrom slås sammen.
5. Teksten trimmes.

Eksempler: «Adobe Commerce (Magento)» gir `adobe commerce`, «Netthavn AS» gir `netthavn`, og «Beste Byrå» gir `beste byrå`.

**Ny tabell `entity_decisions`:**

| Kolonne | Type |
|---|---|
| `tenant_id` | bigint, RLS |
| `brand_id` | sammensatt FK `(tenant_id, brand_id)` med kaskade |
| `name_key` | string(200) |
| `display_name` | string(200) |
| `variants` | jsonb, de ulike skrivemåtene sett ved beslutningen |
| `decision` | `competitor`, `other`, `alias` eller `ignored` |
| `target_type` | `brand` eller `competitor`, nullable (for `alias` og `competitor`) |
| `target_id` | nullable |
| `created_competitor` | bool |
| `alias_ids` | jsonb, aliasene beslutningen la til |
| `reclassified_mentions` | int |
| `decided_by` | user-ID |
| `decided_at` | tidspunkt |

Tabellen er unik på `(tenant_id, brand_id, name_key)`. Modellen bruker `BelongsToTenant`, og policyen krever `competitors.manage`.

**Køen:** `App\Prompts\EntityQueue::pending(Brand, array $marketIds, int $days = 30): list<QueueItem>`.

- Den bygger på `MetricsService::unknownBrands`, grupperer på `EntityName::key` og regner andelen som distinkte svar over alle variantene.
- Den utelater kjente navn (dagens `UnknownBrands::knownNames`, også gjennom `EntityName::key`) og navn med beslutning.
- Den tar bare med navn i minst 2 svar.
- Hvert element har `key`, `name` (vanligste skrivemåte), `variants`, `answers`, `share`, `markets`, `engines`, `last_seen` og `suggestion`.

**Forslag** er regelbasert, uten modell:

- **`other`:** navnenøkkelen står i `config('ekko.entities.known_platforms')`. Startlisten er Shopify, WooCommerce, WordPress, Magento, Adobe Commerce, BigCommerce, Wix, Squarespace, Klarna, Vipps, MobilePay, Stripe, PayPal, Adyen, Nets, Bring, PostNord, Posten, DHL, Helthjem, Google, Meta, Facebook, Instagram, TikTok, Amazon, Microsoft, SAP, Visma og HubSpot.
- **`alias`:** nøkkelen er lik eller begynner med nøkkelen til en konkurrent eller merkevaren. Forslaget har målet med.
- **`ignored`:** nøkkelen inneholder ett av ordene i `config('ekko.entities.generic_words')` (for eksempel «byrå», «nettbutikk», «leverandør») og ingen stor forbokstav i noen variant.

`UnknownBrands::suggestions` erstattes av `EntityQueue`. Det samme gjelder i `NewCompetitor`-detektoren og i tiltaksmotoren for `new_competitor`, slik at navn med beslutning ikke varsles eller blir tiltak.

**Hva beslutningene gjør:**

| Beslutning | Virkning |
|---|---|
| `competitor` | Oppretter `competitors` med `display_name` og alle varianter som alias (`case_insensitive`). Er navnet tidligere arkivert, gjenopprettes det. Pakkens grense gjelder som i dag (`pitch.limits`). Nevnelser klassifiseres på nytt. |
| `alias` | Legger variantene som alias på målet (`brand_aliases` eller `competitor_aliases`, `case_insensitive`). Nevnelser klassifiseres på nytt til målet. |
| `other` | Lagres bare som beslutning. Navnet forsvinner fra køen og vises under Andre aktører med andel. Det teller ikke i share of voice, kildegap eller eierskap, fordi det ikke er en konkurrent. |
| `ignored` | Lagres bare som beslutning. |

**Ny klassifisering:** jobben `ReclassifyEntityMentions` (arver `TenantJob`) oppdaterer `mentions` med `entity_type = unknown`. Den gjelder nevnelser der `EntityName::key(surface_name)` er lik beslutningens nøkkel, svaret tilhører merkevarens markeder og `responses.collected_at` er innenfor de siste 90 dagene.

- Feltene settes til `entity_type` (`brand` eller `competitor`) og `entity_id`.
- For berørte lokale datoer sendes `WorkSignals::signal(WorkKind::Aggregation, <dato>)`.
- Antallet lagres i `reclassified_mentions`.
- Eldre perioder får ikke de nye navnene. Det står i ADR-en.

**Angre** (`DELETE`) reverserer beslutningen:

- fjerner aliasene i `alias_ids`
- arkiverer konkurrenten når `created_competitor` er satt
- setter de omklassifiserte nevnelsene tilbake til `unknown` (samme nøkkel og vindu) og aggregerer på nytt
- sletter beslutningen

Angre er tillatt i 24 timer. Etter det sendes brukeren til konkurrentens panel for å arkivere.

**Konkurrenter:**

- `competitors.position` (int). Migreringen setter plassen etter dagens rekkefølge (`name`, `id`), slik at fargene ikke endres.
- Fargeplassen er `position` blant aktive konkurrenter. Alle stedene som i dag regner `color_index` på egen hånd, skal bruke `Competitor::colorIndexes(Brand)`: `BrandCompetitorController`, `App\Dashboard\CompetitorPage` og `App\Dashboard\SourcePage`, i tillegg til dashboard, rapporter, pitch og API der de viser konkurrentfarger.

**Ruter:**

| Metode og sti | Navn | Tillatelse | Request og svar |
|---|---|---|---|
| `POST /brands/{brand}/entity-decisions` | `brands.entities.decide` | `competitors.manage` | `keys[]` (1–100), `decision`, `target_type?`, `target_id?` (påkrevd for `alias`). Svar: JSON `{ decisions: [{ id, key }], undo_ids: [...] }` |
| `DELETE /brands/{brand}/entity-decisions/{decision}` | `brands.entities.undo` | `competitors.manage` | 422 `undo_expired` etter 24 timer |
| `GET /brands/{brand}/entity-decisions/{key}/excerpts` | `brands.entities.excerpts` | `tenant.view` | JSON med tre utdrag (80 tegn på hver side, uten råsvar-URI) |
| `PATCH /brands/{brand}/competitors/{competitor}` | `brands.competitors.update` | `competitors.manage` | `name?`, `is_active?` |
| `POST /brands/{brand}/competitors/reorder` | `brands.competitors.reorder` | `competitors.manage` | `ids[]`, alle aktive konkurrenter |
| `POST /brands/{brand}/competitors/{competitor}/aliases` | `brands.competitors.aliases.store` | `competitors.manage` | `alias`, `match_mode`. Regex valideres med `RegexAliasGuard`. |
| `PATCH /brands/{brand}/competitors/{competitor}/aliases/{alias}` | `brands.competitors.aliases.update` | `competitors.manage` | `match_mode` |
| `DELETE /brands/{brand}/competitors/{competitor}/aliases/{alias}` | `brands.competitors.aliases.destroy` | `competitors.manage` | |
| `POST /brands/{brand}/competitors/{competitor}/domains` | `brands.competitors.domains.store` | `competitors.manage` | `domain` (normalisert) |
| `DELETE /brands/{brand}/competitors/{competitor}/domains/{domain}` | `brands.competitors.domains.destroy` | `competitors.manage` | |

- **Utdrag:** de inneholder tekst fra svar, slik `PromptShow` allerede viser. De logges ikke og legges ikke i cache.
- **MCP og API:** `list_brands` og API-konkurrentene får `position`. Det kommer ingen nye MCP-verktøy.
- **Avklaringer fra U21** (ADR 0043):
  - Alias på egen merkevare (`target_type = brand`) krever i tillegg `brands.manage`, både for beslutningen og for angre. Uten den svarer ruten 403. Alias på en konkurrent krever en aktiv konkurrent.
  - Angre setter bare tilbake nevnelsene beslutningen flyttet (lagret i `entity_decisions.mention_ids`) og nevnelser funnet etter beslutningen gjennom aliasene den la til. Det skjer i samme forespørsel, ikke i jobben.
  - `EntityName::key` fjerner den juridiske endelsen etter tegnsettingen, slik at «Netthavn AS.» også gir `netthavn`. Symboler (® ™ & +) behandles som tegnsetting.
  - Forslaget `alias` krever at nøkkelen er lik målets nøkkel, eller starter med den etterfulgt av et ord. Forslaget `ignored` gjelder når ett ord er eller slutter på et generisk ord, og ingen skrivemåte begynner med stor bokstav.
  - `keys[]` normaliseres med `EntityName::key` på serveren. Et navn uten ukjente nevnelser de siste 90 dagene, et kjent navn eller et navn med beslutning gir 422 på `keys.<n>`.
  - `undo_ids` er beslutningenes id-er, de samme som `{decision}` i `DELETE`. Angre etter 24 timer svarer `422 { code: "undo_expired", message }`.
  - `{alias}` og `{domain}` i stiene er id-er. Konkurrentrutene svarer med JSON `{ competitor: { id, name, is_active, position, color_index, aliases: [{ id, alias, match_mode }], domains: [{ id, domain }] } }`. `reorder` svarer `{ color_indexes: { <id>: <plass> } }`.
  - Utdragene svarer `{ key, excerpts: [{ before, match, after, engine, market: { id, label }, collected_at }] }`.
  - En ny konkurrent får plassen etter alle andre, også arkiverte. Konkurrentlisten på `brands.competitors.index` sorteres etter fargeplass.
  - `list_brands` får `competitors: [{ id, name, position }]` for de aktive konkurrentene i fargerekkefølge.
  - `brands.entities_reviewed_at` nullstilles ikke av angre.
- **Tester:**
  - `EntityName::key` med tabell av eksempler.
  - Kø, forslag og hver beslutning med virkningen sin.
  - Angre, også etter 24 timer.
  - Ny klassifisering og aggregeringssignal.
  - Detektor og tiltak som hopper over navn med beslutning.
  - Farger etter endret rekkefølge.
  - Isolasjon, også at en beslutning i tenant A ikke påvirker B.

### 6.4 Markeder, identitet, integrasjoner og matchtest (U23)

| Metode og sti | Navn | Tillatelse | Request og svar |
|---|---|---|---|
| `POST /brands/{brand}/markets` | `brands.markets.store` | `markets.manage` | `country`, `language`, `location_label?`, `copy_from?` (marked-ID med samme `language`). Oppretter markedet med `plan_mode = inherit` og kopierer aktive prompts (tekst, tema, intensjon, trakttrinn, prioritet) innenfor grensen. Pakkens `max_markets_per_brand` gjelder. Redirect til `brands.setup.markets`. |
| `PATCH /brands/{brand}/markets/{market}` | `brands.markets.update` | `markets.manage` | `is_active?`, `location_label?` |
| `PATCH /brands/{brand}/identity` | `brands.identity.update` | `brands.manage` | `name?`, `primary_domain?`, `status?` (`active` eller `paused`) |
| `POST /brands/{brand}/aliases` | `brands.aliases.store` | `brands.manage` | `alias`, `match_mode` |
| `PATCH /brands/{brand}/aliases/{alias}` | `brands.aliases.update` | `brands.manage` | `match_mode` |
| `DELETE /brands/{brand}/aliases/{alias}` | `brands.aliases.destroy` | `brands.manage` | Det siste aliaset kan ikke slettes (422). |
| `POST /brands/{brand}/domains` | `brands.domains.store` | `brands.manage` | `domain`, `include_subdomains` |
| `PATCH /brands/{brand}/domains/{domain}` | `brands.domains.update` | `brands.manage` | `include_subdomains` |
| `DELETE /brands/{brand}/domains/{domain}` | `brands.domains.destroy` | `brands.manage` | |
| `PUT /brands/{brand}/integrations/{type}` | `brands.integrations.update` | `brands.manage` | samme felt og validering som innstillingene i dag |
| `POST /brands/{brand}/aliases/test` | `brands.aliases.test` | `tenant.view` | `alias`, `match_mode`, `entity` (`brand` eller `competitor:<id>`). Svar: JSON `{ matches, scanned, window_days: 30, samples: [{ excerpt, engine, market, date }], conflicts: [{ type, name, count }] }` |

**Matchtesten:**

- Den bruker `EntityMatcher` og `AliasPattern` fra gjenkjenningen (ADR 0010) mot høyst 2 000 svar fra de siste 30 dagene i merkevarens markeder, nyeste først.
- `conflicts` er treff der samme tekstområde i dag er gjenkjent som en annen enhet.
- Regex valideres med `RegexAliasGuard` (422 ved ugyldig eller farlig mønster).
- Kallet er begrenset til 30 per minutt per bruker.
- Svaret logges ikke.

Som i gjenkjenningen teller første forekomst av enheten én gang per svar. Et annet registrert merkenavn eller en konkurrent gir konflikt når det lagrede treffet overlapper det foreslåtte treffet. Hvert svar telles høyst én gang per konfliktpart. De tre nyeste eksemplene har `excerpt: { before, match, after }`, motorens nøkkel, `market: { id, label }` og `date` som ISO-tidspunkt.

Ved kopiering av prompts prioriteres aktive kjerneprompts, deretter øvrige aktive prompts, med ID som stabil rekkefølge innen hver gruppe. Land/språk må være ledig på merkevaren. Frem til U17 registrerer `brands.setup.markets` går vellykket opprettelse til `brands.settings`. Integrasjonstypene er `ga4` og `clickhouse_logs`, med hver sin gruppe felt fra dagens innstillinger.

**Tjenester:**

- `App\Brands\MarketLibrary`
- `App\Brands\IdentityLibrary`, som flytter logikken fra `BrandSettingsController::update` ut, slik at den gamle ruten fortsatt virker til U27

**Tester:** tilgang, grenser, kopiering av prompts, matchtest med kjente svar (eksakt ord mot uten skille på store og små bokstaver mot regex), konflikter og isolasjon.

### 6.5 Innboks, helse, varselregler og rapportplaner (U25)

**`App\Inbox\InboxQuery`:**

- `items(Team, ?Brand, ?Market, ?string $type, bool $handled): list<InboxItem>`
- `counts(Team, ?Brand, ?Market = null): array` gir antall ventende rader for `all`, `alert`, `entity`, `action`, `claim` og `integration`. En gruppe teller som én rad. Sidemenyen bruker `all`.

Den har ingen egen tabell. Den samler fra kildene under:

| Type | Kilde | Behandlet når |
|---|---|---|
| `alert` | `alerts` (siste 30 dager), samlet etter 5.6 | `acknowledged_at` er satt |
| `entity` | `EntityQueue` for navn over terskelen i regelen `new_competitor` | beslutning finnes |
| `action` | `actions` med `status = new` | statusen er en annen |
| `claim` | `claims` med `verdict = contradicted` uten tilknyttet tiltak, siste 30 dager, gruppert per faktum | tiltak er laget, eller faktum er slått av |
| `integration` | `integrations` med `status = error` | statusen er en annen enn `error` |

**`InboxItem`:**

```
{ id: "<type>:<kilde-id>", type, brand: { id, name }, market: { id, label } | null, at, edge,
  title, detail, actions: [{ key, label, primary }], target_url }
```

- **Tekst:** `title` og `detail` lages fra språknøkler i `lang/*/inbox.php`. Varsler bruker `AlertPresenter`, og presenterens `line()` blir offentlig som `present(Alert): array{title, detail}`.
- `handled=1` inkluderer også behandlede rader. Hver rad har `handled` og `source_ids`. Varselgrupper har også `members` med lesbar tekst per varsel. Ved gruppekvittering sendes opptil 200 ID-er om gangen. `flash.acknowledged_ids` inneholder ID-ene denne brukeren faktisk kvitterte. Angre bruker disse ID-ene. Nye merkenavn har `suggestion` fra navnekøen. Etter et bestilt rettetiltak inneholder `flash.action_id` tiltakets ID.
- **`edge`:** en av `warning`, `error`, `medium-beige`, `marker-action` og `marker-model`.

**Ruter:**

| Metode og sti | Navn | Tillatelse | Request |
|---|---|---|---|
| `POST /alerts/acknowledge` | `alerts.acknowledge.bulk` | `alerts.manage` | `ids[]` (1–200) |
| `DELETE /alerts/acknowledge` | `alerts.unacknowledge.bulk` | `alerts.manage` | `ids[]` (angre) |
| `POST /brands/{brand}/claims/{claim}/action` | `brands.claims.action` | `prompts.manage` | Lager et `fact_correction`-tiltak fra påstanden etter reglene i 11.3 og 11.6 og lenker påstandene for samme faktum. |
| `PUT /brands/{brand}/alert-rules/{type}` | `brands.alert-rules.upsert` | `alerts.manage` | Send feltene som redigeres. Valgbare felt er `is_active`, `threshold` og `channels[]` (`slack`, `email`, `inbox`). |
| `POST /brands/{brand}/report-schedules` | `brands.report-schedules.store` | `reports.manage` | `cadence`, `recipients[]`, `locale`, `branding` |
| `PUT /brands/{brand}/report-schedules/{schedule}` | `brands.report-schedules.update` | `reports.manage` | som `store` |
| `DELETE /brands/{brand}/report-schedules/{schedule}` | `brands.report-schedules.destroy` | `reports.manage` | |
| `POST /brands/{brand}/report-schedules/{schedule}/send-test` | `brands.report-schedules.test` | `reports.manage` | Sender siste periode til innlogget bruker. Begrenset til 3 per time. |
| `POST /brands/{brand}/checklist/hide` | `brands.checklist.hide` | `tenant.view` | – |

- **`channels`:** står allerede i `alert_rules.channels`. Kanalen `inbox` er alltid på og kan ikke slås av.
- **Gamle ruter:** `alerts.rules.*` og `reports.schedules.*` beholdes til U27 og bruker de samme tjenestene.

**`App\Brands\BrandHealth::for(Brand): array`:**

```
{ markets: [{ id, label, last_completed_at, next_due_on }], engines_active, answers_per_month,
  problems: [{ kind, severity, text, url }], checklist: [{ key, done, optional, text, hint, url }], checklist_hidden }
```

Ny tabell `user_brand_preferences`: `tenant_id`, `user_id`, `brand_id` og `checklist_hidden_at`, unik på `(tenant_id, user_id, brand_id)`.

`brands.entities_reviewed_at` settes av U21 når køen blir tom etter en beslutning. U25 leser feltet.

**Tester:**

- Hver innbokstype, med og uten behandling.
- Gruppering.
- Tellinger lik summen av radene.
- Tekst uten interne nøkler.
- Regler og rapportplaner per merkevare.
- Isolasjon.

## 7. Raske rettinger (U16)

1. **Varsler** (`AlertController::index`): `subject` erstattes av `title` og `detail` fra `AlertPresenter`. Ingen side, e-post eller Slack-melding viser interne nøkler. Testen leter etter `market:`, `prompt:` og heksadesimale hasher på 32 tegn i HTML-en.
2. **Formatering av forbruk og admin-forbruk:** beløp, tall og datoer formateres med en midlertidig `resources/js/format.js` med `formatNumber`, `formatCurrency` og `formatDate`. U18 utvider filen. Rekkefølgen U16 og så U18 betyr at U18 bygger videre på filen.
3. **Stavefeil:** «Dashbord» blir «Oversikt» i `nb`.
4. **Standardperiode** (`DashboardPeriod`): uten `days`, `from` og `to` i URL-en velges den lengste av 7, 28 og 90 dager der antall dager med `agg_daily` *inne i det vinduet* dekker minst 80 % av lengden. Eldre dager utenfor vinduet teller ikke. 7 dager fra start, 28 dager fra 23 dager i de siste 28, og 90 dager fra 72 dager i de siste 90. Samme lengde brukes ved delvis innlasting av innsikt og filtre, via hurtiglager for den lokale dagen.
   - Har merkevaren under 7 dager med data i vinduet, brukes 7 dager.
   - Datamerknaden vises bare når første måledag er etter periodens start. Den står i `bg-shell-surface border-line-soft rounded-xl` med info-ikon: «Data fra *dato*. Perioden er satt til *n* dager, og grafene fylles ut etter hvert som målingene kommer.»
   - En grafserie uten data før første dag tegner et skravert område med «Ingen målinger før *dato*». For uke og måned skraveres bare bøtter som slutter før første måledag. Kryping og trafikk bruker andre kilder og skraveres ikke ut fra `agg_daily`.
5. **Share of voice og trend** på `Brands/Show`: høyst 6 konkurrenter, sortert etter andel i perioden, med farge 0–5 i den rekkefølgen. Begge grafene bruker den samme rekkefølgen og de samme fargene. Resten blir «Andre (*n*)» med `--data-neutral` og stiplet linje. Andelen summeres. Synlighetstrenden bruker snittet, så raten holder seg mellom 0 og 1. «Andre» kan ikke velges som filter. Oversikten har ingen slik graf.
   - Forklaringen er en liste ved siden av grafen (flex `1 1 170px`) med fargeprikk, navn og verdi. Den går under grafen ved smal bredde og ligger aldri oppå aksen.
6. **Prompt-heatmap** (`brands.prompts.index`): med valgt marked vises bare prompts i markedet. Ved «Alle markeder» grupperes radene per marked med markedsnavn i gruppeoverskriften. Tekstene får to linjer før de kuttes, med full tekst i `title`. Rader uten celler viser «Ikke målt i perioden».

**Akseptkriterier:** punkt 1–6 har tester. Referansebildene er oppdatert for sidene som er berørt.

## 8. Opprydding (U27)

- Fjern `Brands/Settings.vue`, `BrandSettingsController::update`, `alerts.rules.*` og `reports.schedules.*` med registeroppføringer. `brands.settings` beholdes som videresending i én versjon.
- Fjern `UnknownBrands` hvis ingen bruker den lenger.
- Sideregisteret har alle nye sider. Utgåtte scenarioer og bilder fjernes.
- Manuell tastatur- og skjermleserkontroll av skall, tabell, panel, matrise og innboks. Resultatet føres i `docs/ui-quality.md`.
- Mobil (390×844): alle nye sider kan brukes, med sidemeny som skuff, panel som full bredde og tabeller med vannrett rulling.
- `docs/spec.md` 14.2 oppdateres med de nye sidene, og de gamle radene fjernes.

## 9. Beslutninger tatt i denne spesifikasjonen

Eier kan overstyre dem på Board før arbeidet starter.

1. **Annen aktør** er en beslutning, ikke en konkurrent med egen rolle. Den vises i egen fane med andel, ikke i grafene. Det holder share of voice, kildegap og eierskap uendret (6.3).
2. **Ny klassifisering** gjelder de siste 90 dagene. Eldre perioder får ikke nye navn.
3. **Promptforslag** regnes som `onboarding`-forbruk og teller mot kvoten, som i veiviseren.
4. **U16 går i produksjon før U17**, slik at de synlige feilene rettes først.
5. **Temaer hører til merkevaren.** Markedene ved et tema er markedene der temaet har prompts. `cross_market_key` vises ikke i grensesnittet.
6. **Skallet byttes i én PR (U17)** uten funksjonsflagg. Gamle ruter sender videre.
