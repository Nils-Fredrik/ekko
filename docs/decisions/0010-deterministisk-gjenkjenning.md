# 0010 – Deterministisk gjenkjenning: normalisering, ordgrenser, lister og regex-sikkerhet

- **Status:** Akseptert
- **Dato:** 2026-11-15
- **Board:** `task:17` / T18

## Kontekst

`docs/spec.md` 8.1 beskriver første ledd i uttrekket kort: normalisering (NFKC, små bokstaver, bevar æ/ø/å/ä/ö), aliassøk med ordgrenser etter `match_mode`, listeposisjon og sitatnormalisering. Flere detaljer må avgjøres for at «100 % treff på gullsettet for kjente alias» skal kunne måles, og svarteksten er data vi ikke kontrollerer, mens regex-alias er tenant-input.

## Beslutning

Koden ligger i `App\Detection`. `DeterministicDetector::detect(Response)` kjøres synkront i workeren, i tenant-konteksten til worker-jobben, og avviser en respons fra en annen tenant enn konteksten (`TenantMismatchException`). Den er idempotent: den erstatter responsens `deterministic`-nevnelser og beholder `llm`-nevnelser.

1. **Normalisering** (`TextNormalizer`): NFKC per grafemklynge, usynlige tegn og kontrolltegn fjernes, all whitespace blir ett mellomrom, typografiske apostrofer blir `'`, og en liten-bokstav-form lagres ved siden av den opprinnelige. For aliassøk foldes i tillegg ö=ø, ä=æ, «aa»=å (Aasen = Åsen) og andre aksenter fjernes (café = cafe). Det gjør at svensk «Björnkaffe» treffer norsk alias «Bjørnkaffe» og omvendt. å, æ og ø bevares ellers. Regex-alias kjøres mot NFKC-teksten uten folding, slik at mønsteret ser bokstavene som de står.
2. **Offset:** `first_char_offset` er antall Unicode-kodepunkter fra starten av den opprinnelige svarteksten (ikke byte, ikke normalisert tekst). `surface_name` er den opprinnelige teksten for treffet, maks 255 tegn.
3. **Ordgrenser:** ingen bokstav eller tall rett før eller etter. En etterfølgende genitiv-s (`Volvos`, `Volvo's`, `Volvo’s`) godtas, lengre ord ikke (`Volvobil`, `Volvoer`, `Volvoene`). Bindestrek-sammensetninger (`Volvo-eier`) regnes som treff. Et domene i teksten (`fjordkaffe.no`) regnes som nevnelse. Flertallsformer og bestemte former er ikke dekket og må legges inn som egne alias.
4. **Match-modi:** `exact_word` er hele ord med skille mellom store og små bokstaver, `case_insensitive` hele ord uten, og `regex` er mønsteret slik det står, uten skille mellom store og små bokstaver (flaggene `iu`), uten automatiske ordgrenser.
5. **Overlapp og én nevnelse per entitet:** overlappende treff løses ved at det lengste vinner («Telenor Norge» foran «Telenor»). Identiske treff regnes ikke som overlapp: to konkurrenter med samme alias (samme konkurrent registrert under to merkevarer) får hver sin nevnelse. Hver merkevare og hver konkurrent gir høyst én nevnelse, nemlig første forekomst.
6. **Hvilke alias:** alle alias for tenantens aktive merkevarer (`brands.status = active`) og aktive konkurrenter på dem, slik 8.1 sier. Merkevarens navn legges ikke inn som alias automatisk; veiviseren (T25) må opprette det som alias.
7. **Listeposisjon** (`ListPositions`): en liste er rader med samme markørtype og innrykk: `1.`, `1)`, `-`, `*`, `+`, `•`, `–`, `—`. Dypere innrykk og vanlige linjer rett under (uten blank linje) hører til elementet over. Blanke linjer avslutter ikke en liste, men et avsnitt uten innrykk etter en blank linje gjør det. En nummerert liste som ble avsluttet slik, fortsetter når neste markør er neste tall (`1. Volvo`, et avsnitt, `2. Saab`), og avsnittene regnes som en del av elementet over. Nummererte overskrifter (`### 2. Saab`) regnes som markører. Posisjonen er elementets 1-baserte plassering i listen, som er lik det skrevne nummeret i en velformet nummerert liste. For en entitet brukes første forekomst *inne i* et listeelement; `list_position` er null hvis entiteten bare står utenfor lister. `first_char_offset` er likevel første forekomst i hele teksten.
8. **Sitater:** `UrlNormalizer` fjerner `utm_*`, `gclid`, `fbclid` og `srsltid`, skråstrek på slutten og `www.`, og i tillegg fragment, standardport og store bokstaver i vertsnavnet. Bare `http` og `https` godtas. `DeterministicDetector` fyller `url_normalized`, `domain`, `ownership` og `competitor_id` på sitatradene som motoradapterne har lagret; andre kolonner røres ikke, og et sitat uten gyldig http(s)-URL beholder sin gamle klassifisering.
9. **Eierskap** (`DomainOwnership`): `own` når domenet treffer et `brand_domains`-domene i tenanten (underdomener bare med `include_subdomains`), ellers `competitor` når det treffer et `competitor_domains`-domene. Tabellen har ikke noe `include_subdomains`-felt for konkurrenter, så konkurrentdomener dekker alltid underdomener, og det mest spesifikke domenet vinner. Domener med æ/ø/å sammenlignes som punycode, både i sitater og i lagrede domener. Merkevaredomene vinner over konkurrentdomene. I en tenant med flere merkevarer regnes alle tenantens merkevaredomener som `own`.
10. **Regex-sikkerhet** (`RegexAliasGuard`): ved lagring (regelen `App\Rules\SafeRegexAlias`) avvises mønstre over 200 tegn, kontrolltegn, ugyldig PCRE, `(?C`-callouts og `(*VERB`, gjentatte grupper som selv gjentas (`(a+)+`), mønstre som kan treffe tom tekst, og mønstre som går tom for backtracking-grensen på fire prøvestrenger. Ved kjøring slås PCRE JIT av og `pcre.backtrack_limit` settes til 200 000, treff per alias begrenses til 200 (også for ordtreff, slik at ett støyende alias ikke skjuler resten), og et mønster som feiler eller treffer grensen hoppes over uten å stoppe resten av gjenkjenningen. Innstillingene gjenopprettes etterpå. Meldingene ligger i `lang/{nb,sv,en}/detection.php`.
11. **Svarteksten er data:** den brukes aldri som mønster eller alias, og ugyldig UTF-8 renses (`mb_scrub`) før bruk.

## Konsekvenser

- Gullsettet (`database/evals/extraction-gold.json`, 64 svar på nb, nn og sv) kjøres i CI og krever 100 % treff på kjente alias og ingen ekstra treff. Nye normaliseringsregler eller alias-tilfeller legges inn i gullsettet.
- Skjemaet for alias-administrasjon (T28) må bruke `SafeRegexAlias` når `match_mode = regex`.
- Worker-jobben (T17) må kalle `DeterministicDetector` i `TenantJob`-konteksten og kan dele én `DetectionCatalog::load()` på tvers av flere svar i samme tenant.
- `detect()` gjenskaper de deterministiske radene, så modellleddet (T20) må kjøre etter den, ikke før.
- Modellleddet (T20) leser nevnelsene med `detection = deterministic` som fasit for om en kjent merkevare er nevnt (8.2).
