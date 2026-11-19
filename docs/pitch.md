# Pitch-prosjekter og pitch-rapport

En pitch-rapport viser et prospekts AI-synlighet før et salgsmøte, uten at prospektet er kunde. Plattformadmin og partner-rådgivere kan opprette et pitch-prosjekt for domene, marked og inntil tre konkurrenter. Veiviseren foreslår 25 prompts som kan redigeres før start. Promptene kjøres daglig i sju dager på de aktive API-motorene ChatGPT, Gemini og Claude Haiku. Prosjektet stopper etter sju dager.

En partner kan ha inntil tre aktive pitch-prosjekter samtidig. Forbruket vises separat og er merket i fakturagrunnlaget. Rapporten oppdateres med nyeste tall og viser synlighet mot konkurrenter, viktige kilder og de tre høyest rangerte åpne tiltakene. Konvertering til ordinær tenant beholder historikken.

Tilgjengelige tiltak oppdateres daglig for aktive pitcher fra eksisterende evidens og maler; antallet avhenger av hvilke funn det faktisk finnes. Etter utløp kan innsendte leverandørjobber hentes ferdig. Innsamlede svar som ennå ikke har en innsendt uttrekksjobb, får deterministisk uttrekk uten nye betalte modellkall. Mangelfulle svar fra en allerede innsendt uttrekksjobb forsøkes heller ikke på nytt mot betalingsmodellen etter utløp.

Den eksisterende daglige innsamlingsplanleggeren legger tiltaksoppdateringen i den samme arbeidskøen når dagens pitchbatch opprettes; ingen ny Cloud Scheduler-ressurs er nødvendig. Oppdateringen bruker aggregatene som er tilgjengelige ved planlegging, og nye svar fra dagens innsamling blir normalt med ved neste oppdatering. Innsendte køoppgaver som ikke er klare innen tolv timer, avsluttes som tidsavbrudd uten betalt ny innsending etter pitchutløpet.

Rapporten kan lastes ned som PDF. Den siste genererte PDF-en lagres privat under tenantens rapportprefiks. Pitch-PDF-en inngår foreløpig ikke i den ordinære rapportlisten eller planlagt e-postutsending, og bruker ikke rapportplanens profil eller språkvalg. For å holde pitchhistorikken atskilt tas bare svar fra kjøringer opprettet før utløp eller konvertering med, også når svarene kommer senere.

PDF-en rendres etter at HTTP-svaret er startet, for å unngå å holde tenantens databasetransaksjon åpen mens Chrome arbeider. Hvis rendringen eller lagringen feiler, kan nedlastingen derfor avbrytes etter at HTTP-status 200 allerede er sendt.

Datamodellen har `teams.kind` (`customer`, `pitch`), `teams.pitch_expires_at`, `teams.pitch_converted_at` og `usage_events.project_kind`. En intern pitch-pakke tillater én merkevare, ett marked, 25 prompts og daglige standardmotorer, med 4000 kall per måned som i Basis. Ved konvertering velges partnerens ordinære pakke. Konvertering endrer ikke tenant-ID. Forbrukshendelser klassifiseres etter prosjektet da tilhørende kjøring eller operasjon startet, også når bokføringen skjer senere.
