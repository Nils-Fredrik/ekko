# T42 – kryssreview (påbegynt)

Board task:42. Gren `t42-agent-review`, fra origin/main dfa284f.

Tidligere lokalt utkast er gjenfunnet og lagt i egen gren. Det inneholder agent-review.yml, sensitive stier, GitHub-orkestrering og en policy som knytter Codex-kvitteringen til korrekt bot, aktuell head og tidspunktet for reviewbestillingen. Følsomme stier inkluderer nå API, MCP, nøkler, databasekonfigurasjon, CI og deploy. To policytester passerer.

Dette utkastet er ikke aktivert eller ferdig validert. Neste steg: verifiser action-versjoner, tillatelser for Claude-kommentarer, botidentitet og Codex-kvitteringens kontrakt; test staleness/baseendringer, forfalsket konklusjon, omsendte hendelser og feil i oppstartssteget; implementer grensen på tre reviewrunder og Board-blocker; unngå doble reviewer med eksisterende claude-code-review.yml. Dokumenter installasjon og aktivering i runbooken sammen med T10s eksisterende utkast.

Aksept krever separate test-PR-er for Codex-, Claude- og følsomme endringer, etterfulgt av verifisert grenbeskyttelse med CI og agent-review som påkrevde sjekker. Eieren merger. Ingen installasjoner, secrets eller grenbeskyttelse er endret i denne oppstarten.
