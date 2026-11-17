# T42 – Akseptbevis for automatisk mergekø

Board: task:61. Køimplementasjonen ble levert i PR #68; dette dokumentet gjelder isolert live aksept og driftsoppsett etter ADR 0026.

## Avgrensning

Testene kjører i det private repoet [ekko-merge-queue-acceptance](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance). Testappen `ekko-merge-queue-test-nf` (App-ID 5807907, installasjon 183105804) har tilgang kun dit. Produksjonsappen `ekko-merge-queue-nf` (5807708, installasjon 183101443) har tilgang kun til `Nils-Fredrik/ekko`. Begge installasjonene og deres eksakte rettigheter er kontrollert gjennom App-autentisering. Midlertidige kontrolltokener ble tilbakekalt.

Testappen har samme rettigheter som produksjonsappen: lese Actions, Administration, Checks, Commit statuses og Metadata; skrive Contents og Pull requests. Ingen bypass er gitt. Private nøkler ligger i respektive `merge-queue`-miljøer, som tillater bare `main`.

Køens kilde er kopiert fra Ekko `5824ed3549c6921c49884f75ff7ff38830b31279`. En eksakt tekstsammenligning bekrefter at bare repo-navn og de to review-identitetskonstantene er tilpasset. Beslutningslogikken er uendret. Testrepoets CI, bygg og deploy er syntetiske og har ingen produksjonstilgang.

## Beslutninger kontrollert mot GitHub

| Tilfelle | Bevis | Resultat |
|---|---|---|
| To samtidige PR-er | [PR 1](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/pull/1), [PR 2](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/pull/2) | Appen merget én PR, ventet på main-CI/deploy og oppdaterte deretter neste gren. |
| App-utløst CI og review | [Review-kjøring 44190825199](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/actions/runs/44190825199), [CI 44190825470](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/actions/runs/44190825470) | Nye kjøringer på ny head etter Appens grenoppdatering; actor er testappen. |
| Feilende CI på utdatert gren | [PR 3](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/pull/3) | `failure`; ingen grenoppdatering eller merge. |
| Avvist review på utdatert gren | [PR 4](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/pull/4) | `failure`; ingen grenoppdatering eller merge. |
| Mergekonflikt | [PR 5](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/pull/5), [PR 6](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/pull/6) | Første endring merget; den motstridende PR-en stoppet med `conflict`. |
| Grønn status med utdatert reviewkvittering | [PR 8](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/pull/8) | GitHub viste clean/grønn status, men køen avviste feil base i kvitteringen med `review`. |
| Native auto-merge | [PR 9](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/pull/9) | Køen returnerte `native-auto-merge-pending`; GitHub gjennomførte deretter auto-merge som eieren. |
| Ny head etter grønn review | [PR 10](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/pull/10) | En tidligere grønn head ble erstattet av en feilende commit. Køen stoppet med `failure` på rød CI. Dette tilfellet tester ikke avvisning av en gammel head-kvittering. |
| Grønn ny head med kvittering for forrige head | [PR 14](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/pull/14) | Alle kontroller og status var grønne, GitHub viste `clean`, men køen returnerte `review`. Verken head eller main ble endret; se detaljene under. |

Review-svarene i disse feilscenarioene er uttrykkelig syntetiske fixtures publisert av testrepoets GitHub Actions-identitet. De er ikke Claude- eller Codex-godkjenninger. Feil-PR-ene er lukket uten merge etter verifikasjon.

### Ny grønn head med gammel kvittering (PR 14)

Testen startet med grønn head `d2a97784e5b915e022c756ae88d048d69e821c98`, og publiserte deretter ny head `72325a73861a417b9033a9e2dcc071db7946fd7d`. Base var uendret `74128f2e04aa5c13d257621f5ab1e086451677b0` gjennom hele kontrollen.

På den nye headen var `test` og `container build` grønne i [CI 44195284855](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/actions/runs/44195284855), `fixture-review` og statusen `agent-review` var grønne i [44195282157](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/actions/runs/44195282157), og separat ekte Claude-review var grønn i [44195282126](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/actions/runs/44195282126). PR-deploy var forventet hoppet over; ingen kontroll feilet eller ventet. GitHub rapporterte `mergeable_state=clean`.

Den syntetiske [kvitteringen](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/pull/14#issuecomment-6963486069) hadde riktig base, `result=success` og riktig statuskjøring `44195282157`, men `head=d2a97784e5b915e022c756ae88d048d69e821c98`. Køkopien returnerte `Merge queue: review (PR #14)` med exitkode 1. Etter kjøringen var PR-en fortsatt åpen og umerget, head fortsatt `72325a73861a417b9033a9e2dcc071db7946fd7d` og main fortsatt `74128f2e04aa5c13d257621f5ab1e086451677b0`. PR-en ble først lukket manuelt etter disse kontrollene. Dermed er gammel head-kvittering testet uavhengig av rød CI og feil base.

## Ekte Claude-review

En separat workflow bruker samme pinnede `anthropics/claude-code-action` som produksjon, et main-avgrenset `agent-review`-miljø og en Team-token opprettet gjennom eierens innlogging. Ingen Console API-nøkkel eller produksjons-App-nøkkel er brukt. PR-diff behandles bare som data; modellen kan ikke kjøre kode eller endre filer.

- [PR 11](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/pull/11): ekte review bestod i [44193652444](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/actions/runs/44193652444).
- [PR 12](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/pull/12): første review bestod i [44193662541](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/actions/runs/44193662541).
- Appen oppdaterte deretter PR 12 til `f11be9a63b484aade72cce90147b672af85c3e1c`. Den nye ekte reviewen bestod i [44193970584](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/actions/runs/44193970584), med `ekko-merge-queue-test-nf[bot]` som actor. Appen merget deretter PR-en; [CI og syntetisk deploy 44194078892](https://github.com/Nils-Fredrik/ekko-merge-queue-acceptance/actions/runs/44194078892) bestod på merge-commit `ad8ab6d635bffb5329132c2885f4de52f5844c7c`.

## Produksjonskontroll og aktivering

Før aktivering består begge runbookens metadataauditer. `main` krever strict base, administratorhåndheving, `test` og `container build` fra GitHub Actions (15368) og `agent-review` fra review-Appen (5773706). Force-push og sletting er av.

`MERGE_QUEUE_APP_ID=5807708` og `MERGE_QUEUE_ALLOWED_AUTHORS=Nils-Fredrik` ligger i `merge-queue`-miljøet. Repo-variabelen `MERGE_QUEUE_BOT_SLUG=ekko-merge-queue-nf` har ingen miljøoverstyring. Nøkkelinnhold er ikke del av beviset.

Repo-variabelen `EKKO_MERGE_QUEUE_ENABLED` ble satt til `true` 17. november 2026. [Første produksjonskjøring 44194296978](https://github.com/Nils-Fredrik/ekko/actions/runs/44194296978) utstedte App-token og verifiserte bot-slug uten feil, men stoppet med `failure (PR #74)` på en reelt rød PR. Ingen produksjons-PR ble endret. Ved kryssreview av dette akseptbeviset ble det avdekket at PR 10 ikke testet gammel head-kvittering uavhengig av rød CI. Køen ble derfor satt tilbake til `false` mens det separate PR 14-tilfellet over ble gjennomført. Den forblir av i påvente av godkjent kryssreview og merge av dette beviset. Aktuell repo-variabel er autoritativ for driftsstatus.

**Gjenstående produksjonsaksept:** Første merge utført av produksjonsappen og påfølgende vellykkede produksjonsdeploy er ennå ikke kontrollert. Task:61 skal forbli åpen til disse er verifisert og dokumentert med App-actor, merge-SHA og CI/deploy-kjøring etter ADR 0026 punkt 5. Verken den første køkjøringens korrekte stopp, syntetisk deploy i testrepoet eller en native auto-merge av denne dokumentasjons-PR-en oppfyller dette punktet. Etter godkjent review og merge kan køen reaktiveres og den første ordinære App-mergen følges gjennom produksjonsdeploy; produksjon skal ikke brukes som testfixture.

Siste kontrollerte produksjonsdeploy var grønn [CI 44158308567](https://github.com/Nils-Fredrik/ekko/actions/runs/44158308567) på `5824ed3549c6921c49884f75ff7ff38830b31279`. Køen kan ikke løse reelle reviewfeil eller konflikter. `EKKO_MERGE_QUEUE_ENABLED=false` stopper nye køoperasjoner; native auto-merge på enkelt-PR-er fortsetter uavhengig av bryteren.

## Lokal verifikasjon

- `node --test tests/merge-queue/*.test.mjs`: 20 bestått.
- `composer test`: 1908 bestått, 11038 assertions, inkludert tenant-isolasjon.
- `composer lint`: Pint og Larastan bestått.
- `npm run lint`: bestått; fire eksisterende `vue/no-v-html`-advarsler, ingen feil.

Dette dokumentet endrer ingen kølogikk eller terskler. App- og nøkkeloppsettet er gjort uten å lagre nøkkelinnhold i kode, PR, Board eller akseptbevis.
