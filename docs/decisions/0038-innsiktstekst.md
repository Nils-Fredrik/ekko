# 0038 – Innsiktstekst på dashboardene

- **Status:** Foreslått
- **Dato:** 2026-11-19
- **Board:** `task:95` / U12. Bygger på [ADR 0034](0034-tiltaksmotor.md) (tallvakt og formulering) og [ADR 0023](0023-anthropic-kredittplan.md) (Anthropic-kreditter). Eierbeslutning på Board #1014: innsiktskallene teller mot tenantens kvote.

## Kontekst

Oversikten (7.1) og merkevaredashboardet (7.2) skal ha en kort tekst om hva som har endret seg. Kravet er at ingen tall i teksten avviker fra tallene på siden. Samme side kan dessuten ha endret seg siden teksten ble skrevet: nye svar kommer inn gjennom dagen.

## Beslutning

**Tall fra databasen, aldri fra modellen.** `InsightFigureBuilder` leser tallene med de samme `MetricsService`-kallene som sidene bruker: nøkkeltall (synlighet, share of voice, anbefalingsrate, siteringsrate, gjennomsnittsposisjon), de største bevegelsene per motor, konkurrent og prompt (høyst `ekko.insights.max_movements` hver), modellbytter i perioden og fullførte tiltak. Modellen får funnene med retning (`up`, `down`, `unchanged`) og navn på plassholdere, ikke tallene, og returnerer `{ "text": "…{visibility_change}…" }`. `InsightTextGuard` avviser teksten hvis den bruker en plassholder modellen ikke fikk, er for lang (320 tegn for en merkevare, 480 for oversikten), eller har et siffer eller en klammer igjen etter at plassholderne og navnene modellen fikk (merkevare, motor, konkurrent, prompt, modell) er tatt ut, lengste navn først. Som i ADR 0034 fanges ikke tall skrevet med ord; prompten forbyr dem. Et navn modellen fikk slipper sine siffer gjennom bare når hele navnet står i teksten, men da hvor som helst i den; en prompt eller konkurrent med et årstall i navnet kan altså gi det årstallet i teksten. Prompttekster kuttes til 120 tegn.

**Bare tall siden viser.** Plassholdere finnes bare for tall som står på siden: nøkkeltallene med endring i prosentpoeng, verdien per motor, og per merkevare (nå og før) på oversikten. Største bevegelser for konkurrenter og prompts, modellbytter og fullførte tiltak nevnes med navn og retning, uten tall, siden ingen side viser disse tallene. Retningen for dem er fra tidspunktet teksten ble skrevet.

**Tallene settes inn ved visning.** Det som lagres er malteksten og retningene da den ble skrevet. `InsightService` leser tallene på nytt hver gang teksten vises og fyller plassholderne med `InsightFormatter`, i samme avrunding som kortene (én desimal, endring i prosentpoeng regnet fra de uavrundede verdiene). Teksten vises derfor alltid med sidens tall, også om nye svar har kommet inn senere samme døgn. Er et tall borte, eller en endring har snudd retning siden teksten ble skrevet, vises ingen tekst; den skrives ikke om før neste døgn.

**Lav n.** En verdi med færre enn `ekko.insights.min_n` (10) svar er ikke et funn. En endring tas bare med når begge periodene har minst så mange. Uten minst ett pålitelig nøkkeltall (merkevare) eller en pålitelig bevegelse (oversikt) gjøres ikke noe kall.

**Lagring.** `insights`: én rad per tenant, filternøkkel og lokalt døgn (unik). Nøkkelen er en hash av omfang (merkevare eller oversikt), merkevare, periode, marked, motor, tema, intensjon og språk (nb, sv eller en, brukerens språk). Raden har status `written` eller `unavailable` med årsak. Radene er tenanteide med radbeskyttelse. Teksten lages ikke ved hver sidevisning: første visning med et nytt filter eller et nytt døgn lager den, resten leser raden. En lås per nøkkel hindrer to samtidige kall. Propen er utsatt i egen gruppe, så grafene ikke venter på modellen.

**Budsjett og kvote.** Før kallet reserveres `ekko.anthropic.insight.estimated_cost_usd` på Anthropic-kontoen uten å være tillatt i redusert modus (ADR 0023); da lages ingen tekst. Kjent svar gjøres opp med `settleKnown` og bokføres i `usage_events` med `kind = insight`, `billed_to = platform`, `calls = 1`. `insight` står ikke i `ekko.usage.platform_paid_kinds`, så kallet teller i tenantens kallkvote og budsjett (Board #1014). Er tenantens kvote brukt opp (100 %), gjøres ikke kallet. Høyst `ekko.insights.max_per_tenant_per_day` (40) kall per tenant og døgn; forsøk med ukjent utfall teller også, siden de kan være fakturert. Kjent avslag frigjør reservasjonen; ukjent utfall beholder den (ADR 0023). «Credit balance is too low» setter kredittstopp. Modell-ID, tokengrenser og priser ligger i `ekko.anthropic.insight` (modell-ID kan settes med `EKKO_INSIGHT_MODEL`). Kallet bruker samme klient som tiltaksformuleringen (`AnthropicActionWriter` med innstillingene `insight`), uten automatisk retry.

**Feil og redusert modus.** Ingen innsiktslinje i stedet for en feilmelding. Årsakene `reduced`, `quota`, `cap` og kjent leverandørfeil (`failed`) prøves på nytt tidligst etter `ekko.insights.retry_after_minutes` (60). En avvist tekst (`refused`) og et ukjent utfall (`unknown`) prøves ikke på nytt samme døgn, siden de kan være fakturert.

**Logg.** Hvert kallforsøk gir én linje, «Insight call.», med tenant, merkevare, omfang, operasjon, utfall (`written`, `refused`, `failed`, `unknown`, `not_reserved`, `not_called`), forbrukshendelse, tokens og kostnad, aldri tekst.

**Av som standard.** Den utsatte propen hentes ved hver sidevisning, også om siden ikke viser den. Teksten er derfor skrudd av (`ekko.insights.enabled`, `EKKO_INSIGHTS_ENABLED`, standard av) til U05 og U10 har plassert `InsightLine` på sidene; da settes standarden til på. Propen er `null` mens den er av.

**Grensesnitt.** `Brands/Show` og `Home` har den utsatte propen `insight` (`null` eller `{ text, label, generated_on }`), og `Components/Dashboard/InsightLine.vue` viser den. Sidene bestemmer plasseringen. Oversikten bruker sidens periode (i dag 28 dager mot de 28 foregående); endres den til 7 dager, følger teksten med.

## Konsekvenser

- Kostnad per tekst: om lag 1 500 tokens inn og 120 ut, omtrent 0,004 dollar med dagens priser. Taket er 40 tekster per tenant og døgn.
- Hver visning leser tallene på nytt (dashboard for perioden og den forrige, konkurrenter, prompts), som er de samme spørringene som siden. Teksten er utsatt, så første svar blir ikke tregere.
- U13 (rapportens forside) kan kalle `InsightService::forBrand` med rapportperioden og få samme tekst og tallkontroll.
- **Avvik fra 12.5:** den daglige teksten har ingen egen post i nivå 2-planleggingen (12.5); den bruker reservasjon og redusert modus, som tiltaksformuleringen. Dette er avvik i påvente av eierens svar på Board #1060. Skal den fordeles som tillegg etter prognosen, må `ekko:anthropic-budget` få en egen post. Den månedlige rapportinnsikten i 12.5 er en annen tekst og er uendret.
