# 0042 – Måleplan: arv og egne markedsvalg

- **Status:** Foreslått
- **Dato:** 2026-11-20
- **Board:** `task:124` / T53

## Kontekst

Spesifikasjonen (6.2 og 7.6) sier at merkevaren har en standardplan, at et marked enten arver den eller har egne valg, og at arvestatusen lagres eksplisitt. Den sier ikke hvordan den effektive planen skal leses. Dispatcheren, førstekjøringen, Anthropic-planleggeren, kostnadsestimatet, motorfiltrene og `list_brands` leser i dag reglene i `market_engines`. T52 endrer frekvensreglene i de samme klassene samtidig.

## Beslutning

**Tabeller.** `brand_plan_engines` (`tenant_id`, `brand_id`, `engine_key`, `frequency`, `prompt_scope`, `repetitions`) holder standardplanen og har samme form som `market_engines`. Tabellen har FORCE RLS, en sammensatt fremmednøkkel `(tenant_id, brand_id)` til `brands` og høyst én regel per motor og omfang. `frequency` tillater de seks verdiene i 6.2. `markets.plan_mode` er `inherit` eller `own`.

**Effektiv plan i `market_engines`.** `market_engines` holder alltid markedets effektive regler. Et marked som arver, har en eksakt kopi av standardplanen. `App\MeasurementPlan\MeasurementPlans` holder kopien oppdatert i samme transaksjon som hver endring av standarden eller arvestatusen. Alle som leser planen, leser dermed én kilde. Dispatcher, førstekjøring og planlegger trenger ingen endring, og T52 kan endre kalenderreglene uavhengig.

Alternativet var å bare lagre egne valg i `market_engines` og slå opp standarden ved lesing. Det krevde endringer i alle lesere og ga en ny måte å lese feil plan på. En kopi kan bli utdatert hvis noen skriver direkte i `market_engines`. Derfor skriver all appkode gjennom tjenesten, og testene sammenligner arvede markeder med standarden etter hver endring.

**Én regel per spørsmål og motor.** Planen har høyst én regel for alle spørsmål (`all`) og én kjerneregel (`core`) per motor. Et kjernespørsmål bruker kjerneregelen hvis den finnes, ellers regelen for alle. Et vanlig spørsmål bruker bare regelen for alle (`MeasurementPlans::ruleFor`). Dette er regelen dispatcheren allerede brukte.

**Endringer.** `PUT /brands/{brand}/measurement-plan` tar `standard.engines` og `markets[]` (`id`, `plan_mode`, valgfritt `engines`) og lagrer alt samlet under merkevarens lås. Hele forespørselen valideres før noe skrives. Et marked som ikke står i forespørselen, beholder modus og regler. `own` uten `engines` beholder markedets nåværende regler, også hvis markedet arvet til nå. `inherit` tar ikke `engines`. Av er tillatt for alle motorer i katalogen. En aktiv regel krever tilgjengelig motor i pakken, frekvens og gjentakelser innenfor pakkens tak og en gjeldende pris. Uten pakke er bare Av tillatt. Når et marked går over til arv, kontrolleres den lagrede standarden på samme måte, siden arven tar reglene i bruk. Hvis standarden ikke lenger kan brukes, for eksempel fordi en pris mangler, avvises endringen med mindre en gyldig standard sendes i samme forespørsel. Det samme gjelder et nytt marked som arver i veiviseren.

**Veiviseren.** En ny merkevare får de valgte motorene som standardplan, eller pakkens standard (7.4) hvis ingen er sendt. Første marked arver. Et nytt marked arver når `plan_mode=inherit` sendes eller ingen motorer er sendt. Hvis motorer sendes uten `plan_mode`, slik dagens veiviser gjør, får markedet egne valg. Med `plan_mode=own` og uten motorer starter markedet med en kopi av standarden. Et nytt pitchprosjekt får pakkens standard, og markedet arver den. Da er oppførselen den samme til T55 tar i bruk arv i veiviseren.

**Radredigering i innstillinger.** Hvis en enkelt rad endres i den eksisterende motorlisten, får markedet egne valg fra sitt nåværende oppsett. Lagring uten endringer påvirker ikke arven.

**Migrering.** Eksisterende rader i `market_engines` endres ikke. Kolonnen `plan_mode` legges til med standardverdien `own`. Det er en katalogendring i PostgreSQL, og alle eksisterende markeder blir egne valg uten omskriving eller RLS-kontekst. Markeder fra eldre kode under utrulling får også egne valg. Appen setter `inherit` eksplisitt. Hver merkevare får `StandardPlan::forTenant` med en kopi av 7.4-standarden som er frosset i migreringen, altså motorer og omfang fra 7.4, én gjentakelse og frekvensen fra det sjeldneste av motorens standard, valgt intervall og pakketaket. Motorer som er utilgjengelige, mangler gjeldende pris eller ligger utenfor pakken, settes til Av. Uten pakke er alle av.

**Frekvenser.** `App\MeasurementPlan\Frequency` har rekkefølgen i 6.2. Lagring godtar bare verdiene `PromptSchedule` kan planlegge i dag (`Frequency::schedulable()`). Når T52 legger til 14 og 21 dager i `PromptSchedule` og `market_engines`, kan de velges uten endring her.

## Konsekvenser

- T54 bruker `MeasurementPlans::draft()` og `preview()` for å validere og løse et utkast per marked uten å skrive. `preview()` gir modus, `is_active` og effektive regler for hvert marked.
- T55 leser `measurementPlan` på innstillingssiden og i veiviseren (`options`, `standard`, `markets`) og lagrer med `PUT /brands/{brand}/measurement-plan`.
- `list_brands` viser `plan` (standarden) per merkevare og `plan_mode` per marked.
- Ny kode som oppretter markeder eller endrer motorregler, skal gå gjennom `MeasurementPlans`.
