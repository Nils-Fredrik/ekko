# ADR 0022 – Kryssreview bundet til kode og base

Status: foreslått; produksjonsaktivering krever aksepttesten i runbooken.

T42 trenger en maskinelt kontrollerbar godkjenning fra påkrevde reviewere. PR-kommentarer alene kan være gamle, forfalsket av en forfatter eller gjelde en annen base.

Vi bruker `pull_request_target` med kun betrodd base-checkout. Diffen leses som data, og review-agenten kjører aldri PR-kode. Den deterministiske gaten sammenligner head, base, test-merge-SHA og tittel før og etter review og setter status på head-commiten. En egen GitHub App er påkrevd kilde til statusen, slik at andre PR-workflows med statuses:write ikke kan forfalske godkjenning. App-nøkkelen ligger kun i environment `agent-review`, med tillatt deploy-gren nøyaktig `main`. Grenbeskyttelsen krever oppdatert base og CI.

Claude leverer en strukturert konklusjon. Codex-integrasjonen gir ikke en offentlig dokumentert status-API med modellvalg og norsk konklusjon. Vi validerer det observerte formatet fra den autentiserte Codex-boten: full head-SHA, fullført kodereview uten funn og ny reviewkvittering etter bestilling. Ukjent format blokkerer; en reaksjon godkjenner aldri. Gaten oversetter et gyldig rent resultat til «GODKJENT» og dokumenterer kilde/revisjon. Modellvalget GPT-6.1 Sol må verifiseres separat i integrasjonen før aksept.

Tre mislykkede ulike head-SHA-er siden siste godkjenning eskaleres til T42 på Board med berørt PR som referanse. Omkjøring av samme kode er ikke en ny reviewrunde. Varslingen bruker en separat Board CI-identitet; nettlesersesjoner og personlige lokale tokens kopieres ikke til GitHub. Manglende varslingstilgang gir rød sjekk. Rundekommentarer må komme fra den dedikerte App-boten, aldri den delte github-actions-boten. Varslingen er minst én gang: dersom Board lykkes og lagring av kvitteringen feiler, kan neste forsøk gi en duplikat med samme PR-referanse.

Dette medfører avhengighet til et observert Codex-format som må testes ved integrasjonsendringer. Grenbeskyttelsen aktiveres først etter separate test-PR-er; eieren merger alltid. Se `docs/runbook.md` for aktivering og feilhåndtering.

Claude leser bare arbeidsmappen i restricted-modus. Diff og kontekst ligger under `.review/`. Publiseringsverktøyet avviser tekst med miljøverdier på minst 16 tegn eller kjente tokenmønstre. Ukjent mergeability ventes på i inntil 30 sekunder, før kjøringen feiler lukket.
