# Visuell regresjon og tilgjengelighet (U15)

`npm run build && npm run test:ui` kjører sideregisteret i `tests/Browser/visual/pages.mjs` i lys og mørk modus, på desktop (1440 × 1000) og mobil (390 × 844). Den offentlige delte rapporten beholder sin faste lyse rapportprofil i begge preferanser. Docker må kjøre. PHP, PostgreSQL og testdatabasen settes opp som for de øvrige nettlesertestene. PDF-fixtures trenger Chromium (`CHROME_PATH` ved lokalt avvikende plassering).

Nettleseren kjører i det offisielle Playwright-imaget, låst til versjon, digest og `linux/amd64`, også på Apple Silicon. Runneren kobler den til PHP-serveren på verten og stopper sin egen container etter kjøringen. Ingen produksjonsdata eller eksterne modellkall brukes. Nettleseroppsettet nullstiller bare den verifiserte `_testing`-databasen; kjør ikke Pest eller andre nettlesertester samtidig mot samme database.

Sideregisteret har 50 scenarioer og 200 referansebilder. I tillegg kontrolleres grafventing, axe-sperren, tenantvarsler, sidemenyen og tastaturfokus i det mobile promptpanelet.

## Referansebilder

Etter en tilsiktet UI-endring:

```sh
npm run build
npm run test:ui:update
npm run test:ui
```

Et mindre utvalg kan kjøres med `-- --project=desktop-dark --grep brand`. Se gjennom endrede PNG-filer før commit. Referansebilder under `tests/Browser/visual/snapshots/` versjoneres i samme PR som UI-endringen. CI oppdaterer dem aldri selv. Fast dato, syntetiske data, lokale skrifter, redusert bevegelse og fast nettleser gir samme grunnlag lokalt og i CI. Den beskjedne toleransen på 100 piksler gjelder samlet antall avvik, ikke en prosentandel av siden.

CI-artifactet `ui-quality-<run>-<attempt>` inneholder:

- Playwrights HTML-rapport med alle sidebilder, axe-funn og forventet/faktisk/diff ved feil.
- `baseline-changes/index.html`, som viser versjonerte referansebilder før og etter PR-en, også når både kode og referanse er oppdatert og testen er grønn. Første PR viser alle bildene som nye.
- `bundle-budget.json`, med størrelser og grenser.

Hvis lokal Docker ikke er tilgjengelig, kan faktiske PNG-er fra en fullført CI-kjøring brukes som grunnlag. Kontroller kjøringens commit og bilder manuelt, kopier bare de tilsiktede nye eller endrede bildene til riktig prosjektmappe, og krev en ny, full bildesammenligning i CI etter commit. Feil i lasting, JavaScript eller axe skal rettes, ikke godkjennes som referanser.

Åpne Playwright-rapporten med `npx playwright show-report playwright-report/ui-quality`. Baselineoversikten er en selvstendig HTML-fil med tilhørende PNG-er.

Innloggingsdata ligger midlertidig i `.ui-auth`, slettes etter testen og lastes ikke opp. Tester bruker reell innlogging og tofaktor for rollene som krever det. Sidekomponenten kontrolleres før bildet tas; en omdirigering til innlogging kan ikke bli en godkjent referanse for en beskyttet side.

Før opptak venter testen på at Vue er montert og alle utsatte Inertia-props er lagt inn, bekreftet gjennom Inertias offentlige `success`-hendelse. Deretter rulles hver ECharts-graf inn i synsfeltet og må ha både `data-chart-ready="true"` og synlig SVG. En egen regresjonstest dekker utsatte grafdata uten lasteskjelett og en graf under skjermkanten. `networkidle` alene er ikke et signal om at siden er ferdig rendret.

`npm run test:ui:contracts` krever at hver Inertia-side har et scenario eller en konkret begrunnelse for at ruten ikke er aktiv. Jetstreams vilkårs- og personvernsider er inaktive fordi funksjonen er deaktivert; dette kontrolleres også. Registrer nye sider og nødvendige syntetiske data i samme PR.

## Tilgjengelighet

axe kjører WCAG 2 A/AA, 2.1 AA og 2.2 AA-regler på alle scenarioer i begge temaer og skjermstørrelser. Alle kritiske funn blokkerer, uten regelunntak. Øvrige funn bevares i rapporten for oppfølging. En grønn automatisk kontroll er ikke en full WCAG 2.2 AA-sertifisering: tastaturflyt, skjermleseropplevelse og tilstander som åpne dialoger må fortsatt vurderes. Eksisterende funksjonstester for temabytte og grafenes tabellalternativer beholdes.

Første U15-kjøring, før fakta- og pitchsidene ble lagt til, hadde 0 kritiske funn etter retting av skjemanavn, og 458 `serious` node-funn. Senere måling etter T49, T45, U11 og datafargene hadde 544 serious (kontrast 530, lenke i tekst 4, treffflate 8, rullefelt 2). U01-F1 bruker mørkere primærflate for hvit tekst, mørkere primærtekst i lyst tema, teksttokenene `--color-success-text` og `--color-error-text` i mørkt tema, understreket partnerlenke, 24 px sammendrag og et fokuserbart rulleområde på robotsiden. CI 44783234593 skannet alle 168 variantene uten axe-funn. Suksessfargen som tekst på lyst skall er 4,20:1, og tonede statusmerker (`bg-primary/15 text-primary`, `bg-success/15 text-success`) er 4,31:1 og 4,01:1 mot hvit flate. De var ikke blant disse funnene og står som avvik i spesifikasjonen. Hover på primær er begrenset til lenker og knapper. De 14 bildeavvikene i den kjøringen er innlogging, profil, team i mørkt tema og delt rapport.

### Tastatur og skjermleser i Arbeidsflaten (U27)

Kontrollert 23. november 2026 med Orca 46.1 og Chromium fra prosjektets Playwright-image, i en isolert Linux-sesjon med de syntetiske engelske testdataene. Tastetrykk ble sendt til det aktive nettleservinduet. Opplesningen ble kontrollert i Orcas talelogg og sammenholdt med fokus og innhold i nettleseren.

| Område | Handling | Observert resultat |
|---|---|---|
| Skall | Tab til «Skip to content», Enter. På mobil åpnet jeg menyen med Enter og lukket den med Escape. | Orca leste hovedinnholdet og overskriften på promptsiden. I mobilmenyen leste Orca dialogen og lukkeknappen. |
| Tabell | I tabellen «Prompts and topics» flyttet jeg fokus med pil ned og åpnet neste rad med Enter. | Orca leste tabellnavn, rad- og kolonnetall og teksten til den nye raden. Riktig prompt åpnet seg. |
| Panel | I mobilpanelet brukte jeg Tab gjennom redigeringsfeltene og Escape for å lukke. | Panelet var 390 px bredt. Orca leste «Topic», «Intent» og «Funnel stage» med feltets verdi. Fokus gikk tilbake til raden for samme prompt. |
| Måleplan | Jeg åpnet en standardcelle med Enter, gikk til intervallet med Tab og lukket redigeringen med Escape. | Cellen hadde motor, kolonne og verdi i navnet. Fokus gikk til «Editing ChatGPT · Brand standard», videre til «Weekly» og tilbake til samme celle. |
| Innboks | Jeg valgte «New brands» med tastaturet. | Filteret hadde antall og valgt tilstand. Fokus ble på filteret, URL-en fikk `type=entities` og listen viste bare merkenavn med tilhørende handlinger. |

Alle seks oppsettssider og innboksen ble åpnet ved 390 × 844. Temaene ligger over prompttabellen, konkurrentfanene får flere linjer og innbokshandlingene bruker plassen under teksten. Brede datatabeller ruller i sitt eget område. Sideregisteret kontrollerer dokumentbredden på disse sju sidene i begge temaer.

## JavaScript-budsjetter

`npm run test:budget` leser Vite-manifestet etter bygg. Størrelsene er gzip nivå 9, uten dobbelttelling av delte moduler. Det største språksettet (JSON og genererte PHP-oversettelser) inngår i førstegangsbudsjettet, mens grafer fortsatt må lastes asynkront.

| Måling                                        | Grense i byte | Etter U24 |
| --------------------------------------------- | ------------: | --------: |
| App og statiske importer + største språkmodul |       180 000 |   161 518 |
| Største første sidelasting + app og språk     |       285 000 |   235 001 |
| ECharts-chunk                                 |       240 000 |   211 419 |
| Alle JavaScript-chunker                       |       780 000 |   705 432 |

Grensene ligger i `tests/Browser/visual/budgets.json`. Overskridelse eller manglende måling stopper CI. En budsjettendring skal begrunnes i PR-en; den skal ikke være en automatisk reaksjon på røde tester. Eksisterende kontroll mot eager-lasting av ECharts og grensen for ukomprimert grafbibliotek gjelder fortsatt.

U24 hever grensen for app og språk fra 161 000 til 180 000 byte, og for alle chunker fra 700 000 til 780 000. Oppsettsidene for markeder, identitet og integrasjoner er nye chunker, og tekstene deres ligger i språksettet som lastes med entry. Etter U28 var entry 160 218 og totalen 690 193. Målt etter U24: entry 161 518 (+1 300) og totalt 705 432 (+15 239). Marginen er bevisst stor, så senere sider og tekster ikke stopper CI på noen hundre byte. Første sidelasting og ECharts har allerede rom (285 000 og 240 000).

U28 hevet grensen for app og språk fra 160 000 til 161 000 byte. Etter U22 lå main på 159 979 byte, og det fantes ingen ubrukte språknøkler igjen. Svarlisten bak sentimentet legger til 201 byte tekst i det største språksettet, selv med forkortede tekster. Hele språksettet lastes i entry, også rene serverfiler som `validation` (3 350 byte gzip) og `http-statuses` (1 030 byte). Å skille dem ut av nettleserbundelen er det varige grepet, men `laravel-vue-i18n` kan ikke utelate filer, så det er en egen oppgave.

Bundlebudsjettet erstatter ikke målingene av p95 HTTP-responstid eller LCP på representative enheter og nettverk. Disse ytelsesmålene gjelder fortsatt; den deterministiske bildesuiten er ikke et produksjonsytelsesbevis.
