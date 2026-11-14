# 0005 – Fremmednøkler for tenant-eide data

- **Status:** Akseptert
- **Dato:** 2026-11-14
- **Board:** `task:7` / T08

## Kontekst

Datamodellen har flere relasjoner mellom tenant-eide tabeller. Fremmednøkler på rad-ID alene sikrer at raden finnes, men kan ikke kontrollere at begge radene tilhører samme tenant. Integrasjonskonfigurasjonen trenger både kryptering og en `jsonb`-kolonne.

## Beslutning

Rader som peker til andre tenant-eide rader, bruker sammensatte fremmednøkler med `tenant_id` og radens ID. Det gjør at databasen sjekker tenant-tilhørighet også ved direkte importer og masseoppdateringer. Eloquent-relasjonene bruker rad-ID-en, kombinert med det globale tenant-scopet.

Valgfrie relasjoner fra prompts til temaer, fra sitater til konkurrenter og fra forbrukshendelser til kjøringer blir nullstilt ved sletting av den refererte raden. PostgreSQL nullstiller bare selve referansen, slik at raden beholder tenant-tilhørigheten.

Integrasjonsinnstillinger lagres som en kryptert JSON-streng i en `jsonb`-kolonne. Eloquent-casten bruker Laravels kryptering og dekoder innstillingene når modellen leser dem. Kolonnen kan fremdeles valideres av PostgreSQL som JSON. Innholdet utelates når modellen serialiseres.

## Konsekvenser

Sammensatte fremmednøkler krever at de refererte tabellene har en unik nøkkel på `(tenant_id, id)`. Ved lesing og skriving av integrasjonskonfigurasjon brukes Eloquent-modellen for kryptering og dekryptering.
