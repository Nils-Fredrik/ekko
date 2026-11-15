# T17 – dispatcher og worker (påbegynt)

Board task:14. Gren `t17-dispatcher-worker`, fra origin/main dfa284f.

Implementert første del: PromptSchedule følger crc32(prompt_id) med søndag=0 for ukedag og 1–28 for månedsdag. FairQueue fletter tenant-iteratorer uten å endre den interne prioriteringsrekkefølgen. Simulering med 3 tenants og 500 prompts passerer: hver prompt nøyaktig én gang per uke, alle ukedager innenfor 25 % av gjennomsnittet, rundgang så lenge tenantene har arbeid. 3 tester / 1333 assertions. Ingen levende motorkall.

Dette er ikke en ferdig dispatcher. Neste del skal opprette idempotente run_batches/runs under tenantkontekst og RLS, velge core først med QuotaGuard/MonthlyUsage, og flette jobbene i databasekøen. Worker må håndtere globale motorgrenser, retry for 429/5xx, permanent 4xx, fakturerbare feilforsøk, DataForSEO submit/poll og seks timers resubmission. Koble deretter Cloud Run-start og batchtellere, og utvid integrasjonstestene før PR.

T11/T19 er merget. T41 venter fortsatt på ferdig T17. Ingen ruter eller produksjonsressurser er endret. Oppgaven skal ikke lukkes før ferdig PR er merget.
