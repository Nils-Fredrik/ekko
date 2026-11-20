# 0035 – ECharts som grafbibliotek

- **Status:** Foreslått
- **Dato:** 2026-11-19
- **Board:** `task:85` / U02 (`docs/ui-forbedring.md` kapittel 5, 6 og U02)

## Kontekst

Spesifikasjonen valgte Chart.js (`docs/spec.md` 4 og 14.1). Appen har to grafer på canvas (`TrendChart`, `VoiceChart`). Grafkatalogen i UI-forbedringen trenger markører og perioder i tidsgrafer, heatmap, treemap, rangeringsgraf og stablede arealer. Grafene skal også bytte tema uten ny lasting og være skarpe i PDF-rapporten. Med Chart.js krever flere av disse tillegg, og canvas blir uskarpt ved utskrift.

## Beslutning

- Apache ECharts erstatter Chart.js. Det importeres modulvis fra `echarts/core`, med bare SVG-tegneren og de grafene og komponentene vi bruker. Registreringen ligger i `resources/js/Components/Charts/support/echarts.js`, og det er den eneste filen som importerer ECharts.
- Biblioteket lastes med `import()` første gang en graf nærmer seg skjermen. Vite legger det i en egen chunk, og sider uten grafer laster det ikke. `tests/Feature/Frontend/ChartBundleTest.php` stopper statiske importer av ECharts.
- Alle grafer går gjennom `<EkkoChart>`, som eier tema, tooltip, lav-n-stil, markører, tabellvisning, tekstbeskrivelse og lasting. Det finnes én komponent per graftype i `resources/js/Components/Charts/`.
- Temaet bygges fra tokenene i `resources/css/app.css` og byttes med `setTheme` når `data-theme` endres.
- `VoiceChart` er fjernet i U05. `TrendChart` og Chart.js er fjernet i U06, da promptsiden viser dagceller i stedet for trendgrafen.

## Konsekvenser

- Grafchunken er ca. 620 kB (212 kB gzip), men den lastes bare på sider med grafer. U15 legger budsjettet inn i CI.
- Nye grafer bruker komponentene i `Components/Charts/`, ikke ECharts direkte. Trengs en ny ECharts-del (graf eller komponent), registreres den i `support/echarts.js`.
- Konkurrenter får farge etter `colorIndex`, sin faste plass i konkurrentlisten, slik at fargen er lik på alle sider.
- SVG gir like grafer i grensesnittet og i rapporten. Rapportens grafer er ikke en del av denne beslutningen.
