# 0040 – Låst Linux-nettleser for visuell regresjon

Status: akseptert i U15 (Board task:98).

## Beslutning

Visuelle referanser tas med Playwright/Chromium i et offisielt Docker-image låst til digest og amd64. Testprosessen og PHP kjører på verten; Playwrights nettverksbro gjør localhost tilgjengelig for nettleseren. Samme kommando brukes på macOS og i Linux-CI. Syntetiske fixtures og fast klokke ligger under `tests/Browser`, og produksjonsrutene endres ikke.

Alle aktive Inertia-sider registreres eksplisitt med rolle og testdata. Et kontraktskrav stopper nye uregistrerte sider. Referanser for fire tema-/skjermkombinasjoner versjoneres. Oppdaterte referanser sammenlignes også med PR-ens basecommit, slik at oppdatering av både kode og forventet bilde fortsatt gir et synlig review-grunnlag.

axe kjøres i samme scenarioer og kritiske funn blokkerer. Bundlebudsjettet måler gzip fra det faktiske Vite-bygget og avviser synkron import av grafbiblioteket.

## Konsekvenser

Docker og mer kjøretid trengs lokalt. Apple Silicon emulerer amd64 for å unngå egne referansesett. Referansebildene øker repoets størrelse, men gjør forventningen tilgjengelig og reviewbar uten en ekstern betalt tjeneste. Større nettleseroppgraderinger krever en samlet, gjennomgått referanseoppdatering. Automatiske axe-funn erstatter ikke manuell tilgjengelighetstesting, og filstørrelsesbudsjetter erstatter ikke reelle responstids- og LCP-målinger.

Kommandoer, rapporter og grenser er beskrevet i [UI-kvalitet](../ui-quality.md).
