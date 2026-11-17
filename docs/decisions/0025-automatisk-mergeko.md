# 0025 – Automatisk mergekø

- **Status:** Foreslått; aktivering krever kontrollene under.
- **Dato:** 2026-11-17
- **Board:** task:61 / T42

## Problem

Flere agenter lager PR-er parallelt. `main` krever oppdatert gren og en review bundet til både head og base. Når en annen PR merges, må derfor både integrasjon og review tas på nytt. Vi ønsker at maskinen skal håndtere dette én PR om gangen, med de samme testene og den samme uavhengige reviewen.

## Beslutning

- Køen kjører bare betrodd kode fra `main`, aldri kode fra en PR. En separat, minst privilegert GitHub App får tilgang kun til dette repoet. Standard `GITHUB_TOKEN` brukes ikke til å oppdatere eller merge PR-er, siden slike hendelser normalt ikke starter nye workflows.
- Kun åpne PR-er mot `main` som ikke er utkast, kommer fra samme repo, har godkjent agenttittel og har en eksplisitt godkjent opphavsperson, vurderes. Rekkefølgen er eldste først. Alle andre ignoreres.
- Hver kjøring gjør høyst én endring: oppdaterer én gren mot `main` eller merger én PR. Oppdatering bruker `expected_head_sha`; en konflikt eller endret head stopper uten merge. Kontroller og kryssreview kjøres på nytt etter oppdatering. En PR med feil, konflikt eller uavklart review stanser køen og vises som feilet GitHub Actions-kjøring. Ventende kontroller får tid til å fullføre.
- Før køen går videre, må siste `main`-commit ha en vellykket push-kjøring av CI med grønne `test`, `container build` og `deploy`. En manglende eller pågående kjøring gir venting; en feilet eller hoppet deploy stopper køen. Det hindrer at flere merges løper foran produksjonsutrullingen.
- Før merge kontrolleres at `main` fortsatt krever strict base, administratorer er omfattet, og `test`, `container build` og `agent-review` er bundet til forventede GitHub Apps. På head må siste relevante kontroller være fullført og grønne. Urelaterte hoppede jobber, som PR-ens deploy, tillates. Reviewkvitteringen må komme fra den dedikerte review-boten og samsvare med både head, base og statusens Actions-kjøring.
- `main`, PR-head, tittel og test-merge-SHA kontrolleres igjen rett før merge. GitHubs merge-API får eksakt forventet head-SHA; grenbeskyttelsen er siste sperre ved en samtidig endring. Ingen admin- eller bypass-rettighet brukes. Bare GitHub kan avgjøre at mergekravene er oppfylt.
- Køen kjører på push til `main`, hvert femte minutt og manuelt. GitHub Actions `concurrency` tillater én aktiv køkjøring. Planlagt kjøring fanger opp tapte hendelser. Den kan ikke automatisk løse en faktisk konflikt eller overstyre en feilet review.
- GitHubs innebygde auto-merge kan kjøre utenfor denne køen. Åpne PR-er med auto-merge får derfor forrang. Hvis den eldste er betrodd og ligger bak `main`, kan køen oppdatere grenen; selve mergen overlates til GitHub. Ellers venter køen. En ekstern merge kan fremdeles skje samtidig; eksakt head, ny basekontroll og GitHubs strict-grenbeskyttelse er siste vern mot konkurrerende endringer.
- Standard er deaktivert (`EKKO_MERGE_QUEUE_ENABLED` er ikke `true`). Aktivering krever separat App, miljøet `merge-queue` begrenset til `main`, eksplisitt tillatte PR-forfattere og live aksept i et isolert testrepo. Et godkjent push til `main` skal fortsatt starte normal CI, og vellykket CI skal starte den ordinære produksjonsutrullingen. Dette skal demonstreres uten å bruke produksjon som testfixture.

## Konsekvenser

Når køen er aktivert, kan en betrodd ferdigmeldt PR merges uten at eieren trykker merge. En merge starter eksisterende deployløp etter CI; jobben tester en kandidat før trafikken flyttes. Stoppknappen er å sette `EKKO_MERGE_QUEUE_ENABLED` til `false`; en allerede startet GitHub-operasjon kan fortsatt fullføres. Utkast og PR-er utenfor tillatelseslisten merges ikke. Varsling er GitHub Actions-status og sammendrag; det er ingen ny automatisk varsling på Board.

Den innebygde GitHub-mergekøen forutsetter organisasjonseid repo etter GitHubs dokumentasjon. Dette personlige repoet bruker derfor en egen kø. Native auto-merge er slått på i repoet, men er per PR; denne køen bruker det beskyttede merge-endepunktet og setter ikke auto-merge på andre PR-er.

## Oppsett og aksept før aktivering

1. Opprett en egen GitHub App installert bare på Ekko, uten bypass-rettigheter. Gi den lesetilgang til Actions, Administration, Checks og Commit statuses, og skrivetilgang til Contents og Pull requests. Bruk ikke review-boten eller standard `GITHUB_TOKEN` til dette.
2. Opprett GitHub Actions-miljøet `merge-queue`, begrenset til `main`. Legg Appens private nøkkel i miljøhemmeligheten `MERGE_QUEUE_PRIVATE_KEY`. Sett variablene `MERGE_QUEUE_APP_ID` og `MERGE_QUEUE_ALLOWED_AUTHORS` (kommaseparerte eksakte GitHub-brukernavn). Hold repo-variabelen `EKKO_MERGE_QUEUE_ENABLED` på `false` mens løsningen verifiseres. Private nøkler skal ikke deles i chat eller legges i kode.
3. Test med en separat App og syntetiske data i et isolert testrepo uten produksjonstilgang. Tilpass repo- og botidentifikatorene for testkopien. Bekreft at Appens grenoppdatering starter CI og review, at Appens merge starter CI på `main`, og at testrepoets syntetiske deploy fullføres. Kontroller to samtidige PR-er, native auto-merge, endret head/base, konflikt, feilet test og utdatert review. Ingen av disse testene skal deploye produksjon.
4. Etter godkjent review, merge og vellykket isolert aksept kan `EKKO_MERGE_QUEUE_ENABLED` settes til `true`. Kontroller første kjøring og produksjonsdeploy. Ved behov settes variabelen tilbake til `false`; PR-er der GitHubs egen auto-merge er aktivert, fortsetter uavhengig av denne bryteren.
