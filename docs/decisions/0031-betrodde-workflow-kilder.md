# ADR 0031 – Betrodde workflow-kilder og uavbrutt review

Dato: 17.11.2026. T39-F9, Board task:59.

## Bakgrunn

Flytende action-tags kan endres uten en endring i Ekko. Review-workflowene
manglet eksplisitt avgrensning til betrodde forfattere og grener i eget repo.
På PR #78 kansellerte en hoppet-over beskrivelsesendring en gyldig review:
workflow_run kjører concurrency-kontrollen før jobbens if-vilkår.

## Beslutning

- Alle eksterne Actions bruker full commit-SHA. Versjonskommentaren dokumenterer
  hvilken tag SHA-en ble løst fra; oppdateringer skjer gjennom vanlig PR/review.
- Automatisk review krever samme head-/base-repo og forfatter med
  OWNER, MEMBER eller COLLABORATOR. Request-jobben avgrenser før App-token;
  kildevalideringen kontrollerer igjen via API før review-credentials opprettes.
  Prepare, diff og gate kontrollerer fortsatt tilliten når PR-en leses.
- Interaktive @claude-omtaler krever en betrodd kommentar-/review-/issue-forfatter.
  PR-er slås opp via API, også for issue_comment, og må ha grener i eget repo
  og base main. Kontrollen kjører fra main med bare GitHub lesetoken; Claude-
  tokenet brukes først etter godkjent kontroll. Checkout beholder ikke credentials.
- Bare vellykkede pull_request_target-forespørsler deler concurrency-gruppe per
  PR. Hoppet-over, kansellerte og mislykkede forespørsler får en egen gruppe
  per run-ID. De kan dermed ikke kansellere et gyldig review. Nye reelle
  forespørsler erstatter fortsatt eldre review av samme PR.
- Eksisterende binding til kildeworkflow, head/base-SHA, botidentitet og ferske
  reviewkvitteringer beholdes. Ingen PR-kode eller artifacts kjøres med review-hemmeligheter.

## WIF-kontroll

Produksjonens GitHub-provider ble lest 17.11.2026 etter T10. Betingelsen stemte
med Terraform: repository_id 1456601992, repository_owner_id 1542112,
ref refs/heads/main, event_name push og workflow_ref
Nils-Fredrik/ekko/.github/workflows/ci.yml@refs/heads/main. Ingen WIF-endring
eller utvidelse av IAM er nødvendig i denne oppgaven.

## Konsekvenser

Eksterne fork-PR-er kan fortsatt testes av vanlig CI, men får ikke automatisk
review på Ekkos betalte integrasjoner. En betrodd vedlikeholder må flytte ønsket
arbeid til en gren i eget repo. Endring av PR-beskrivelse avbryter ikke review;
endringer i kode, tittel eller base følger fortsatt vanlig ny review-runde.
