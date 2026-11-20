# 0039 – Produkt-API og MCP for tiltak, fakta, rapporter og pitch

- **Status:** Foreslått
- **Dato:** 2026-11-20
- **Board:** `task:83` / T50, kontrakter i Board #899.

## Kontekst

Tiltak, faktasjekk, rapportdeling og pitch er implementert for web. REST og MCP skal gi samme funksjon uten en alternativ statusflyt, kvote eller tilgangsmodell.

## Beslutning

`ActionInput` og `ActionQuery` deler kontrakten for tiltak mellom REST og MCP, og bruker `ActionWorkflow`. Fakta bruker `BrandFactManager`. Rapportlenker flyttes fra web-kontrolleren til `ReportLinks`, slik at web, REST og MCP deler hashing, utløp og tilbakekalling. Alle tre innganger logger opprettelse/tilbakekalling med rapport- og lenke-ID. MCP legger i tillegg en post per skrivende verktøykall med tilkoblings-ID. Ingen tekster, URL-hemmeligheter eller hashverdier logges.

`ProductData` serialiserer eksplisitte offentlige felt. Rapportens lagrede øyeblikksbilde brukes uten å regenerere eller sende rapporten. PDF-lenken krever normal nettleserinnlogging. Delbar tilgang krever en uttrykkelig skriveoperasjon. Modelltekster merkes som ubetrodde i MCP.

REST-tokenets tenantbinding beholdes også for partneren. Pitch-opprettelse velger derfor tokenets bundne partner, aldri en vilkårlig forelder fra input. MCP bruker sin allerede autoriserte tenantkontekst. `PitchProvisioner` kaller den eksisterende, partnerlåste `PitchProjects`-kvoten og oppretter deretter merkevare, normalisert domene, alias og marked i én transaksjon. Kun den nyopprettede tenanten åpnes midlertidig i RLS; opprinnelig kontekst gjenopprettes etterpå, også ved feil.

Opprettelsen gjør ingen nettsidehenting, promptgenerering eller betalte modellkall. Brukeren legger til prompts via prompt-editoren eller API/MCP og aktiverer motorer i markedsinnstillingene. Den nye-merkevare-veiviseren brukes ikke etter at merkevaren og markedet finnes. Sju dagers levetid og kvoten på tre aktive prosjekter starter ved opprettelse, som for web. Nye kall er ikke automatisk idempotente; klienter må kontrollere resultatet før retry. Status-PATCH til samme status er idempotent og endrer ingen tiltaksdatoer.

## Konsekvenser

- Ingen ny datamodell eller migrering. Alle eksisterende token-, rolle- og RLS-kontroller beholdes.
- En partner bruker et eget tenantbundet REST-token for data i det nye prosjektet. MCP kan velge prosjektet gjennom eksisterende partnertilgang.
- Samlingene følger eksisterende paginatorformat med maks 100 rader. MCP bruker cursor og avgrenset databasespørring.
- Eldre rapporter uten lagret nettinnhold returnerer `content: null`, metadata, tiltaksseksjon og eventuell PDF-lenke.
- Alle nye ruter og MCP-verktøy er med i isolasjonsregisteret. Tester dekker fremmede URL- og body-ID-er, roller, OAuth-samtykke, kvote, logg uten hemmeligheter og gjenopprettet tenantkontekst.
