# 0043 – Beslutninger om ukjente merkevarer

- **Status:** Foreslått
- **Dato:** 2026-11-21
- **Board:** `task:132` / U21
- **Spesifikasjon:** `docs/ux-arbeidsflate.md` 6.3

## Kontekst

Navn som gjenkjenningen ikke kan plassere, lagres som nevnelser med `entity_type = unknown`. Til nå ble de sammenlignet med `lower(trim(surface_name))`, og eneste handling var «legg til som konkurrent». Et navn som var en betalingsløsning, et generisk ord eller en skrivemåte av egen merkevare, kom tilbake i forslagsveggen, i `new_competitor`-varsler og i `new_competitor`-tiltak hver uke. Arbeidsflaten (U22) trenger en kø der hvert navn behandles én gang, og der beslutningen også gjelder historikken.

Konkurrentfargen var plassen blant aktive konkurrenter sortert etter navn. Det ga ny farge til alle konkurrenter etter den nye i alfabetet hver gang en konkurrent ble lagt til, og fargen kunne ikke velges.

## Beslutning

**Navnenøkkel.** `App\Detection\EntityName::key` gir én nøkkel for alle skrivemåter av et navn: NFKC og små bokstaver, innhold i parentes fjernes, tegnsetting og symboler (® ™ & +) blir mellomrom, mellomrom slås sammen, og en juridisk endelse (`as`, `asa`, `ab`, `aps`, `oy`, `gmbh`, `ltd`, `inc`, `llc`, `bv`) fjernes som siste ord. Endelsen fjernes etter tegnsettingen, slik at «Netthavn AS.» og «Netthavn, AS» også gir `netthavn`. Den fjernes én gang og aldri hvis navnet bare er endelsen. Nøkkelen er høyst 200 tegn. Nøkkelen regnes i PHP, fordi NFKC og Unicode-klassene ikke har en sikker motpart i SQL. `MetricsService::unknownBrands` grupperer derfor på trimmet skrivemåte i SQL og slår sammen gruppene i PHP. Andelen er distinkte svar over alle skrivemåtene, ikke summen av dem.

**Én kø for alle lesere.** `App\Prompts\EntityQueue` leser ukjente navn for siden, `new_competitor`-detektoren og tiltakskilden `new_competitor`. Den utelater navn merkevaren kjenner (navn og alias for merkevaren og for konkurrenter som ikke er arkivert, sammenlignet gjennom nøkkelen) og navn med beslutning. Køen viser navn i minst to svar de siste 30 dagene. `UnknownBrands` er fjernet. Varselets og tiltakets nøkkel bygger nå på `EntityName::key`. For navn der nøkkelen er endret (endelse, parentes eller tegnsetting), kan et åpent tiltak derfor bli erstattet av et nytt én gang.

**Beslutninger.** Tabellen `entity_decisions` har én rad per merkevare og nøkkel, med FORCE RLS og sammensatt fremmednøkkel til `brands`. Beslutningen er `competitor`, `other`, `alias` eller `ignored`:

- `competitor` oppretter konkurrenten med alle skrivemåtene som alias (`case_insensitive`). En arkivert konkurrent med samme nøkkel gjenopprettes i stedet. Pitchgrensen på tre konkurrenter gjelder.
- `alias` legger skrivemåtene som alias på merkevaren eller en aktiv konkurrent. Alias på egen merkevare krever i tillegg `brands.manage`, fordi merkevarens alias ellers bare kan endres av administratorer.
- `other` og `ignored` lagres bare som beslutning. Annen aktør er ikke en konkurrent med egen rolle, så share of voice, kildegap og eierskap endres ikke.

Beslutningen lagrer skrivemåtene, aliasene den la til og om den opprettet eller gjenopprettet en konkurrent, slik at angre kan reversere nøyaktig det den gjorde. Skrivemåtene hentes fra ukjente nevnelser de siste 90 dagene. Et navn uten slike nevnelser, et kjent navn eller et navn med beslutning avvises. `brands.entities_reviewed_at` settes når køen er tom etter en beslutning. Angre nullstiller ikke feltet.

**Ny klassifisering.** Jobben `ReclassifyEntityMentions` (arver `TenantJob`) flytter ukjente nevnelser med beslutningens nøkkel i svar fra merkevarens markeder de siste 90 dagene til merkevaren eller konkurrenten, og sender `WorkSignals::signal(WorkKind::Aggregation, <lokal dato>)` for hver berørt dag. Signalet sendes uansett `ekko.work.chaining`, fordi aggregeringshandleren alltid er registrert og den planlagte aggregeringen bare dekker de siste dagene. Eldre perioder beholder navnet som ukjent. Det er et bevisst valg: en beslutning skal ikke endre tall i rapporter som allerede er sendt fra lang tid tilbake, og jobben holdes kort. Jobben låser beslutningen og lagrer id-ene til nevnelsene den flyttet. Hvis beslutningen er angret før jobben kjører, flytter den ingenting.

**Angre.** Innen 24 timer kan en beslutning angres. Angre reverserer bare det beslutningen gjorde, i én transaksjon: nevnelsene jobben flyttet (lagret i `entity_decisions.mention_ids`) og nevnelser med nøkkelen som gjenkjenningen fant etter beslutningen gjennom aliasene den la til, blir ukjente igjen, og dagene aggregeres på nytt. Nevnelser som tilhørte målet før beslutningen, beholder koblingen, også når en arkivert konkurrent ble gjenopprettet. Deretter fjernes aliasene beslutningen la til, konkurrenten den opprettet eller gjenopprettet arkiveres, og beslutningen slettes. Angre av alias på egen merkevare krever `brands.manage`, som beslutningen. Angre venter på en jobb som kjører for samme beslutning, fordi begge låser beslutningen. Etter 24 timer svarer ruten 422 med `code: undo_expired`. Da arkiveres konkurrenten eller aliaset fjernes i konkurrentpanelet.

**Forslag uten modell.** Forslagene er regler i `config('ekko.entities')`. Et navn på listen over kjente plattformer foreslås som annen aktør. Et navn med samme nøkkel som, eller en nøkkel som starter med nøkkelen til merkevaren eller en aktiv konkurrent etterfulgt av et ord, foreslås som alias av den lengste treffet. Kravet om ordgrense hindrer at «Nordlyset» foreslås som alias av «Nord». Et navn der ett ord er eller slutter på et generisk ord («byrå», «reklamebyrå»), og der ingen skrivemåte begynner med stor bokstav, foreslås ignorert. En modell ville gitt bedre forslag for noen navn, men kostet et kall per navn og gitt forslag som ikke kan forklares eller testes.

**Fast fargeplass.** `competitors.position` er konkurrentens plass. Migreringen nummererer konkurrentene per merkevare etter `(name, id)`, som var rekkefølgen fargene ble regnet fra, så ingen farge endres ved utrulling. En ny konkurrent får plassen etter alle andre, også arkiverte, så de andre beholder fargen. En gjenopprettet konkurrent havner sist. Fargen er indeksen blant aktive konkurrenter sortert etter `(position, id)` og leses fra `Competitor::colorIndexes(Brand)`. Konkurrentsiden, kildesiden, dashbordet (rekkefølgen i seriene fra `MetricsService`), rapportens linjediagram, API-et og `list_brands` bruker samme rekkefølge. `POST …/competitors/reorder` gir de aktive konkurrentene plassene de hadde seg imellom i ny rekkefølge, slik at en inaktiv konkurrent beholder sin plass. Grafcachen (`GraphCache`) har de aktive konkurrentene i fargerekkefølge i nøkkelen, så en ny rekkefølge eller en endret aktiv-status vises med en gang.

**Utdrag.** `GET …/entity-decisions/{key}/excerpts` viser tre utdrag fra de nyeste svarene med 80 tegn på hver side av navnet. Utdragene er svartekst, som på promptsiden. De logges ikke, legges ikke i cache og har ingen referanse til råsvaret.

## Konsekvenser

- Et navn behandles én gang. Det kommer ikke tilbake i køen, i varsler eller i tiltak.
- Figurene for de siste 90 dagene følger beslutningen etter neste aggregering. Eldre perioder gjør det ikke.
- Konkurrentlisten på analysesiden sorteres etter fargeplass, ikke navn.
- U22 bygger skjermen på rutene i 6.3. U25 leser `EntityQueue` og `brands.entities_reviewed_at`.
