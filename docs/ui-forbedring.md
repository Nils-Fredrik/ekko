# UI-forbedring

Uttrykket skal fortsatt være Varde og Borg (`docs/spec.md` 14.1). Forbedringen ligger i flatene og datafargene, ikke i en ny visuell identitet.

## 4.1 Flater

Kort og paneler var hardkodet `bg-white`, mens skallets flatetokens nesten ikke ble brukt. Mørkt tema, som er standard, viste hvite bokser på svart bakgrunn.

Alle kort og paneler bruker skallets flatetokens, slik at begge temaer fungerer:

| Nivå | Token | Bruk |
|---|---|---|
| 0 | `--color-shell` | Sidebakgrunn |
| 1 | `--color-shell-panel` | Seksjoner og kort |
| 2 | `--color-shell-surface` | Kort i kort, felter, popovers, tooltips |
| Linje | `--color-line` | Skiller og grafrutenett. Mørk verdi i mørkt tema, lys verdi i lyst tema. `--color-line-soft` er den svakere varianten. |

I mørkt tema er `--color-shell-panel` lik `--color-shell`. Nivå 0 og 1 skilles av `--color-line`.

`bg-white` og `text-ink` er fjernet fra komponentene og erstattet med tokens. `text-shell-muted` er den dempede tekstfargen. En ESLint-regel stopper nye forekomster av disse klassene, `fill-black`/`stroke-black`, vilkårlig hex og farger i hakeparentes, også i Vue-maler. Google-merkets fire farger på innloggingssiden er tillatt, fordi merket må være gjenkjennelig.

Jetstream-komponentene bruker fortsatt gray-, indigo- og red-klassene. Verdiene følger temaet: lys tekst og mørke flater i mørkt tema, den opprinnelige lyse paletten i lyst tema.

Merkevaremerket er et svart glyf. I mørkt tema inverteres det, så flisen kan følge skallet. QR-koden for tofaktor beholder en hvit stillesone (`bg-qr-quiet`) i begge temaer, fordi skanneren trenger lys bakgrunn.

## 4.2 Datafarger

Fargene ligger i tema-filen som egne tokens, med verdier for mørkt og lyst tema. De står på `:root`, ikke i `@theme`, fordi diagrammene leser dem fra beregnet stil og Tailwind ellers fjerner ubrukte temavariabler. Merkene under er like i begge temaer og har minst 3:1 mot skall- og surface-bakgrunnen.

| Token | Bruk |
|---|---|
| `--data-own` | Egen merkevare. Lik `--color-primary`. |
| `--data-cat-1` … `--data-cat-6` | Konkurrenter, i fast rekkefølge. Avledet fra beige- og burgunder-tonene. |
| `--data-seq-0` … `--data-seq-6` | Sekvensiell skala for heatmap, i primærfargens tone. `--data-seq-0` er skallflaten og `--data-seq-6` er `--data-own`. Skalaen går fra flaten til primær, så de første trinnene er flaten selv og kan ikke samtidig ha 3:1 mot den. |
| `--data-positive`, `--data-negative`, `--data-neutral` | Endringer og sentiment. Positiv og negativ følger `success` og `error`. Nøytral er en beige. |
| `--data-marker-model`, `--data-marker-action` | Markører for modellbytte og tiltak. |

## U01 – Flater og datafarger

Flatetokens og datafarger etter 4.1–4.2 i tema-filen, for begge temaer. Alle `bg-white`, `text-ink` og andre hardkodede farger i komponentene byttes til tokens. Lint-regelen stopper nye hardkodede farger, unntatt Google-merkets faste farger.

- Ingen hvite flater i mørkt tema (nettlesertest av sidene en innlogget leser kan åpne, pluss innlogging).
- Datafargene har kontrast på minst 3:1 mot bakgrunnen i begge temaer.

## 5. Grafbibliotek

Apache ECharts, importert modulvis fra `echarts/core` og tegnet som SVG. Begrunnelsen står i [ADR 0034](decisions/0034-echarts-grafbibliotek.md). Bare `support/echarts.js` importerer ECharts, og den lastes med `import()` første gang en graf er nær skjermen. Vite legger biblioteket i en egen chunk (ca. 620 kB, 212 kB gzip), og sider uten grafer laster den ikke. `tests/Feature/Frontend/ChartBundleTest.php` sjekker byggemanifestet, og nettlesertesten `tests/Browser/charts.spec.mjs` sjekker nettverkskallene. Budsjettet i CI kommer i U15.

Alle grafer går gjennom `<EkkoChart>` i `resources/js/Components/Charts/`. Den eier:

- **Tema:** bygget fra tokenene i 4.1–4.2 (`support/palette.js`) og byttet med `setTheme` når `data-theme` endres, uten ny lasting.
- **Tooltip:** verdi, endring og n i alle grafer (`tooltipHtml` i `support/format.js`). Lav n står med ord i tooltipen.
- **Lav n:** `low_sample` fra API-et, ellers n < 10. Linjer blir stiplet og dempet, stolper og celler dempet, stablede arealer får et dempet bånd.
- **Markører:** `{ key, date, kind: 'model' | 'action', label, detail?, href? }`. En markør havner i siste bøtte som starter på eller før datoen, så den treffer riktig punkt i daglig, ukentlig og månedlig visning. Markører etter `until` utelates. Klikk gir hendelsen `marker` med markøren (T45).
- **Tabellvisning og tekstbeskrivelse:** knappen «Vis som tabell» og en beskrivelse for skjermlesere (`aria-describedby`). Tabellen viser n ved hver andel. Hver graftype lager beskrivelsen selv hvis siden ikke sender `description`.
- **Bevegelse:** 300 ms ved første tegning, ingen ved oppdatering eller med `prefers-reduced-motion`.
- **Klikk:** hendelsen `select`.

Konkurrenter får farge etter `colorIndex` (fast plass i konkurrentlisten), ikke etter rekkefølgen i grafen, så de har samme farge på alle sider.

## 6. Grafkatalog

| Graf | Komponent | Data |
|---|---|---|
| KPI-minigraf | `SparklineChart` | `points: [{ x, value, n, low_sample }]` |
| Linjegraf mot konkurrenter, forrige periode og markører | `TrendLineChart` | `categories`, `series: [{ key, label, own?, colorIndex?, points }]`, `previous`, `markers`, `until` |
| 100 % stablet areal (share of voice) | `StackedAreaChart normalized` | som over |
| Små multipler per motor | `SmallMultiplesChart` | `categories`, `panels: [{ key, label, points }]`, `markers: { [panel]: [...] }` |
| Heatmap tema × motor (en overskriftslinje per tema) | `HeatmapChart` | `rows: [{ key, label, group }]`, `columns`, `cells: [{ row, column, value, n }]`; `select` gir `{ row, column }` |
| Prikkrad 14 dager | `DotRowChart` | `points: [{ x, value, n }]` |
| Rangeringsgraf topp 6 | `RankChart` | `series: [{ ..., points: [{ rank, n }] }]` |
| Divergerende sentiment | `DivergingBarChart` | `rows: [{ key, label, negative, neutral, positive, n }]` |
| Kilder etter eierskap | `RankedBarChart` | `bars: [{ key, label, value, n, group: own \| competitor \| third_party }]` |
| Kildegap | `GapBarChart` | `rows: [{ key, label, own: { value, n }, competitors: { value, n } }]` |
| Treemap etter kildetype | `TreemapChart` | `items: [{ key, label, value, color? }]` |
| Henting per AI-robot | `StackedAreaChart` | `kind="count"` |
| AI-trafikk | `TrendLineChart` | `kind="count"` |
| Trakt hentet → sitert → besøk | `FunnelChart` | `steps: [{ key, label, value, description }]` |

`kind` er `rate` (andel 0–1, endring i prosentpoeng), `count` eller `number`. Eksempelsiden `/dev/charts` (`routes/dev.php`) viser alle graftypene med testdata i begge temaer. Ruten registreres bare i `local` og `testing`.

## U02 – `<EkkoChart>` og grafbibliotek

ECharts fra `echarts/core` med SVG-tegning, ett tema bygget fra tokens som byttes ved temabytte, felles tooltip, lav-n-stil, markør-API, tabellvisning, tekstbeskrivelse og asynkron lasting. Én komponent per graftype i kapittel 6, med eksempelside i utviklingsmodus.

- Temabytte endrer grafene uten ny lasting.
- Grafbiblioteket lastes ikke på sider uten grafer.
- `TrendChart` og `VoiceChart` er erstattet og Chart.js fjernet når U05–U10 er ferdige.
