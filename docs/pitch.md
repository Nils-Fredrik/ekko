# Pitch-prosjekter og pitch-rapport

En pitch-rapport viser et prospekts AI-synlighet før et salgsmøte, uten at prospektet er kunde. Plattformadmin og partner-rådgivere kan opprette et pitch-prosjekt for domene, marked og inntil tre konkurrenter. Veiviseren foreslår 25 prompts som kan redigeres før start. Promptene kjøres daglig i sju dager på de aktive API-motorene ChatGPT, Gemini og Claude Haiku. Prosjektet stopper etter sju dager.

En partner kan ha inntil tre aktive pitch-prosjekter samtidig. Forbruket vises separat og er merket i fakturagrunnlaget. Rapporten oppdateres med nyeste tall og viser synlighet mot konkurrenter, viktige kilder og de tre høyest rangerte åpne tiltakene. Konvertering til ordinær tenant beholder historikken.

Datamodellen har `teams.kind` (`customer`, `pitch`), `teams.pitch_expires_at`, `teams.pitch_converted_at` og `usage_events.project_kind`. En intern pitch-pakke tillater én merkevare, ett marked, 25 prompts og daglige standardmotorer, med 4000 kall per måned som i Basis. Ved konvertering velges partnerens ordinære pakke. Konvertering endrer ikke tenant-ID. Forbrukshendelser klassifiseres etter prosjektet da tilhørende kjøring eller operasjon startet, også når bokføringen skjer senere.
