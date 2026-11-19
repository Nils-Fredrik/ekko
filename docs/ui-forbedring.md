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

## 4.4 Bevegelse, lasting og tomme tilstander

Grafer tegnes inn på 300 ms ved første visning. Nytt filter eller temabytte tegner dem på nytt uten animasjon. `prefers-reduced-motion` slår av både inntoning og skjelettpulsen.

Skjelettet har samme form som kortene og grafen, og vises mens en ny side lastes. Tomme tilstander forklarer hvorfor det er tomt og hva som skjer videre, med lenke til veiviser eller innstillinger der det er relevant. Tidspunktet for neste kjøring er ikke hardkodet: målingen skjer etter midnatt, og tallene vises dagen etter.

## 7.9 Kommandopalett

`⌘K` / `Ctrl+K`, eller knappen Søk, åpner søk på tvers av merkevarer, prompter, konkurrenter og sider, og hurtigvalg for å bytte tenant og lage rapport. Treffene kommer fra tenanten i forespørselen. Tiltak er ikke en modell ennå, så den gruppen kommer med T43.

Paletten bruker Headless UI.

## U14 – Kommandopalett, skjelett og tomme tilstander

Kommandopalett etter 7.9, skjelettvisning for kort og grafer, tomme tilstander etter 4.4 og bevegelse som respekterer `prefers-reduced-motion`.

- Alle sider i applayouten har skjelett under sidelasting og forklarende tomtilstand der listen eller grafen kan være tom.
- Søket returnerer bare data fra tenanten brukeren har tilgang til.
