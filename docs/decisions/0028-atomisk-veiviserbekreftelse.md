# 0028 – Atomisk bekreftelse av veiviseren

- Status: Foreslått
- Dato: 2026-11-17
- Board: task:29 / T29

To forespørsler kan ha lest samme `ready`-forslag. En kort cachelås som frigjøres før forespørselens ytre RLS-transaksjon committes gir ikke atomisk beskyttelse. En status i Redis/array kan dessuten overleve en rollback i PostgreSQL.

Bekreftelsen låser tenant-raden med `FOR NO KEY UPDATE`, leser analysen på nytt og lagrer konfigurasjonen, første batch, kvotestatus, databasekøjobber og bekreftelsesmarkør i samme transaksjon. Låsen holdes gjennom den ytre request-transaksjonen og serialiserer også forskjellige forslag som konkurrerer om siste plass i pakken. Køen må bruke samme databaseforbindelse, uten utsatt innsetting etter commit.

Markøren er en boolsk verdi i eksisterende `cache`-tabell på den aktive tenantforbindelsen, med eget prefiks, tenant-id og analyse-id. Den inneholder ingen kundeopplysninger og varer like lenge som forslagets TTL fra bekreftelsen, altså minst så lenge som det opprinnelige forslaget. Forslaget kan fortsatt ligge i en annen cache. Ved lesing overstyres status med `confirmed` når markøren finnes. Feil eller ytre rollback fjerner både markør og nye rader; et nytt forsøk er dermed mulig uten duplikater.

Første batch bruker `QuotaGuard`, gjeldende motorpris og riktig plattform/BYOK-budsjett. Avviste kjøringer merkes `skipped_quota` og kølegges ikke. Dagens batch inneholder også standardprompts som er forfalt etter ordinær frekvens, slik at første batch ikke stjeler dagens plan fra dispatcheren. Vanlige kjerneprompts får første prøve umiddelbart; delt plattform-Sonnet følger sin ukentlige eller sjeldnere frekvens. Senere kjøringer bruker den ordinære dispatcher og Anthropic-planleggeren. Leverandørkallet har fortsatt sin ordinære reservasjon før HTTP.

Workerstart skjer i `DB::afterCommit`; en startfeil rapporteres og ordinær scheduler kan plukke opp de varige køjobbene. UI krever et kostnadsestimat for den aktuelle konfigurasjonen, ignorerer forsinkede svar på gamle estimater og viser alle valideringsfeil.

Tester dekker gamle `ready`-objekter, rollback ved køfeil og i ytre transaksjon, idempotent første batch, kvote, plattform/BYOK, workerstart etter commit og markedsforslag. Chromium går fra analyse til bekreftelse og kontrollerer køjobbene i testdatabasen. Eksterne analyser erstattes bare i testserverens router; produksjonskode laster ingen testfakes.
