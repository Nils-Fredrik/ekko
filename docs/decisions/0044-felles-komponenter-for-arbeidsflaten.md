# 0044 – Felles komponenter og formatering for arbeidsflaten

- **Status:** Foreslått
- **Dato:** 2026-11-21
- **Board:** `task:129` / U18
- **Spesifikasjon:** `docs/ux-arbeidsflate.md` 4.2–4.6

## Kontekst

Arbeidsflaten (U19–U27) vedlikeholder data i tabeller med flervalg, detaljpanel og lagring per felt. Før U18 hadde hver side sin egen tabell, og tall og datoer ble formatert med `Intl`, `toLocaleString` eller `useDateTime` på 22 steder, med ulikt språk (`document.documentElement.lang`) og nettleserens tidssone. Spesifikasjonen ber om ett formateringslag, én datatabell bygget på TanStack, et detaljpanel bygget på Headless UI `Dialog`, automatisk lagring med tilbakerulling og en angre-melding. Noen av valgene under står ikke i spesifikasjonen eller avviker fra den.

## Beslutning

**Formatering.**

- `resources/js/format.js` er eneste sted for tall, valuta, prosent, prosentpoeng og datoer. Språket er den delte propen `locale` (nb-NO, sv-SE, en-GB). Tidssonen er tenantens, delt som ny prop `tenant: { timezone }` fra `HandleInertiaRequests` (`null` for gjester). `app.js` kaller `configureFormat` ved første side og hvert besøk. Innstillingene er reaktive, så et språkbytte oppdaterer formaterte verdier uten ny lasting.
- Tekster med flertall og relative dager («i natt», «om 3 dager») kommer fra `lang/*/workspace.php`. `Intl.RelativeTimeFormat` er valgt bort fordi ICU skriver «om 3 døgn» på norsk.
- ESLint-regelen `ekko/format-through-format-js` stopper `Intl`, `toFixed(` og `toLocaleString(` (og dato- og tidsvariantene) utenfor filen, også i Vue-maler. Et unntak må være `eslint-disable-next-line ekko/format-through-format-js -- begrunnelse`. Regelen `ekko/format-exception-needs-reason` og en kontraktstest som leser alle filene i `resources/js`, stopper andre former: unntak uten begrunnelse, på samme linje eller som blokk, `eslint-disable` uten regelnavn og konfigurasjonskommentarer for reglene. Kontraktstesten trengs fordi et direktiv på første linje kan skjule enhver ESLint-rapport.

**Datatabell.**

- `@tanstack/vue-table` 9 eier kolonnemodell, sortering og kolonnevalg, med bare de funksjonene vi bruker registrert (`columnVisibilityFeature`, `rowSortingFeature`, sortert radmodell). `@tanstack/vue-virtual` 3 rendrer bare synlige rader over 200 rader.
- Utvalg, shift-område, gruppering, tastatur og lagring av kolonnevalg ligger som rene funksjoner i `Components/UI/dataTable.js`, ikke i TanStacks utvalgs- og grupperingsfunksjoner. Utvalget er en `Set` av rad-id-er som siden eier (`v-model:selection`), og grupperingen er bare visning. Det gjør reglene testbare i Node og holder TanStack-oppsettet lite.
- Tabellen bruker `role="grid"` med ett radfokus i tabulatorrekkefølgen. Avkrysning og åpning nås med `x` og `Enter`.

**Detaljpanel.**

- Fra 1280 px er panelet en `<aside>` ved siden av tabellen, ikke Headless UI `Dialog`. `Dialog` fanger alltid fokus og gjør resten av siden inert, og da virker verken tabellen eller `j`/`k` mens panelet er åpent. Under 1280 px brukes `Dialog` med bakgrunn og fokusfelle, som i spesifikasjonen.
- `?open=<id>` styres av `useOpenRow`. Åpning legger til en historikkoppføring med Inertias klientbesøk (`router.push`), slik at tilbake lukker panelet. Bytte av rad og lukking erstatter oppføringen (`router.replace`). Spesifikasjonen sa `replace` for alt, men da lukker ikke tilbake panelet.
- `vue` er løftet til `^3.5` for `useId`, som gir stabile id-er for faner og etiketter.

**Lagring og angre.**

- `useAutosave` endrer visningen før svaret og ruller tilbake til siste verdi serveren har godtatt ved feil. Besøkene er asynkrone, så ulike felt ikke avbryter hverandre. Lagringer til samme rad og felt (URL og felt) går én om gangen, og bare den nyeste ventende verdien sendes etterpå. Da får serveren dem i riktig rekkefølge. Når panelet bytter rad, sendes ventende verdier for den gamle raden fortsatt til dens URL, men de endrer bare visningen hvis raden er åpen igjen når de lykkes.
- `useUndoToast` er én delt instans med én melding om gangen. Meldingsområdet (`UndoToast.vue`) står i `AppLayout`. Angre kaller den motsatte handlingen; det finnes ingen utsatt sletting.

## Alternativer som ble vurdert

- **TanStack Table 8:** kjent API, men 9 er gjeldende stabile versjon, har funksjoner som kan velges bort for mindre bundle, og er det som vedlikeholdes videre.
- **TanStacks egne utvalg og gruppering:** krever radmodeller for gruppering og utvidelse og gir rad-id-er som tekst. Egen logikk er kortere og testbar uten Vue.
- **Headless UI `Dialog` også på desktop:** følger ordlyden i 4.4, men stenger tabellen ved siden av panelet.
- **Seriell lagringskø for alle felt:** enklere, men gjør at en bryter venter på et tregt tekstfelt. Køen per rad og felt gir rekkefølge der det trengs og samtidighet ellers.

## Konsekvenser

- Bundleøkning (gzip): app og språk +2,3 kB, største første sidelasting +3,1 kB, all JavaScript +25 kB. TanStack lastes bare av sider som bruker `DataTable`.
- Datoer på eksisterende sider vises nå i tenantens tidssone og uten år i inneværende år. Engelske grafakser bruker en-GB («22 Oct»). Referansebildene er oppdatert.
- Nye sider bruker `format.js`, `DataTable`, `Drawer`, `useAutosave`, `BulkBar` og `useUndoToast` i stedet for egne varianter. `Pages/Dev/Components.vue` viser variantene og står i sideregisteret.
- Kommandopalettens handlinger vises etter `workspace.can` fra skallet (U17). Til U17 deler `workspace`, viser paletten bare «Lag rapport nå», som før.
