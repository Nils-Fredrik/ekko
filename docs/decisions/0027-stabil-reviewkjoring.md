# ADR 0027 – Stabil reviewkjøring og tydelige utfall

Dato: 17. november 2026. T42, Board task:62. Supplerer ADR 0022.

## Bakgrunn

Kjøring 43968768066 publiserte GODKJENT på PR #40, men action v1.0.276 forkastet resultatet etter 36 steg fordi `--max-turns 30` var satt. Det samme skjedde ved 31 og 42 steg. Gate behandlet en teknisk feil som et faglig avslag, også i tellingen mot Board. Streng likhet mellom kildeforespørselens base og execution-checkout skapte dessuten feil når main ble oppdatert før oppstart.

## Beslutning

- Fjern steggrensen. Begrens Claude-steget til 20 minutter og jobben til 25. Behold vellykket action, strukturert resultat, eksakt publiseringskvittering, bot-ID og snapshot som vilkår for godkjenning. Ingen gjenbruk av en kommentar etter teknisk jobbfeil.
- Skill faglig avslag (`failure`) fra teknisk feil og manglende/ukjent kvittering (`error`). Begge blokkerer merge. Bare faglig avslag teller mot tregrensen; Codex-funn må ha samme ferske bot-/SHA-/kvitteringsbevis som en godkjenning.
- Valider fortsatt source-runens repo, workflow-ID/sti, event, forsøk og resultat. Bruk runens uforanderlige head-SHA, siden tilknyttet PR-metadata kan endres. Dersom PR-head er uendret, kan gjeldende main ved execution-oppstart være ny reviewbase. Den må være nøyaktig execution-checkout; hele diffen reviewes med nye kvitteringer. PR-kode og artifacts kjøres aldri.
- Foreldede forespørsler stopper før privilegerte steg. Gate kontrollerer snapshot og siste status fra den dedikerte App-boten før publisering. En eldre kjøring overskriver ikke en allerede observert nyere forespørsel/godkjenning, heller ikke for samme head. Bare egen status kan invalideres; ingen foreldet kjøring teller som faglig avslag.
- Dersom main flytter seg etter execution-oppstart, oppdateres PR-grenen for å starte ny review. Ingen automatiske ubegrensede omkjøringer eller svekkede godkjenningskrav.

## Verifikasjon og grenser

Node-testene dekker tidsbudsjett-kontrakten, tekniske feil, ekte avslag, manglende/forfalskede kvitteringer, fersk main før oppstart, gamle forespørsler og nyere statuser på samme head. Eksisterende tester for bot-identitet, isolasjon av hemmeligheter og kildevalidering beholdes. GitHubs status-API har ingen atomisk compare-and-swap: samtidighet begrenses av workflowens PR-gruppe, og snapshot/status leses på nytt før skriving. Grenbeskyttelsen og fersk base/CI gjelder fortsatt. Live workflow-verifikasjon følger eierens merge; en PR kjører review-koden fra main.
