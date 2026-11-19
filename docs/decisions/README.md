# Arkitekturbeslutninger (ADR)

Beslutninger som ikke står i `docs/spec.md`, dokumenteres her (`AGENTS.md`, `docs/spec.md` 19.2).

- Én fil per beslutning: `NNNN-kort-tittel.md`, fortløpende nummerert.
- Malen: [0000-mal.md](0000-mal.md).
- En beslutning endres ikke i ettertid. Den erstattes av en ny ADR, og den gamle får status «Erstattet av NNNN».

| Nr                                              | Tittel                                                                                     | Status    |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------ | --------- |
| [0001](0001-app-i-rotmappen-og-testdatabase.md) | Laravel-appen i rotmappen og egen testdatabase per worktree                                | Akseptert |
| [0002](0002-tenant-kontekst.md)                 | Tenant-kontekst og regler for tenant-eide modeller                                         | Akseptert |
| [0003](0003-rls-rolle-og-transaksjon.md)        | Rolle, transaksjon og migreringshjelper for row-level security                             | Akseptert |
| [0004](0004-roller-og-tenanttilgang.md)         | Roller, partner-tilgang og «opptre som tenant»                                             | Akseptert |
| [0005](0005-isolasjonstestsuite.md)             | Isolasjonstestsuite med ruteregister                                                       | Akseptert |
| [0006](0006-tenant-eide-tabellrelasjoner.md)    | Fremmednøkler for tenant-eide data                                                         | Akseptert |
| [0007](0007-terraform-oppstart-og-deploy.md)    | Terraform, databasebrukere og deployansvar                                                 | Akseptert |
| [0008](0008-innlogging-og-invitasjoner.md)      | Innlogging med Google, invitasjoner og tofaktor                                            | Akseptert |
| [0009](0009-policyer-og-revisjonslogg.md)       | Policyer for tenant-eide modeller og revisjonslogg                                         | Akseptert |
| [0010](0010-deterministisk-gjenkjenning.md)     | Deterministisk gjenkjenning: normalisering, ordgrenser, lister og regex-sikkerhet          | Akseptert |
| [0011](0011-modellbasert-uttrekk.md)            | Modellbasert uttrekk: batcher per tenant, strukturert utdata, sammenstilling og evaluering | Akseptert |
| [0012](0012-aggregering-og-metrikker.md)        | Aggregering og metrikker: dagsaggregat, MetricsService og modellbytte-markører             | Akseptert |
| [0013](0013-kvoter-og-forbruk.md)               | Kallkvote, budsjetter og forbruksoppdatering                                               | Foreslått |
| [0014](0014-botloggimport.md)                   | Daglige øyeblikksbilder for robottrafikk                                                   | Foreslått |
| [0015](0015-ga4-synk.md)                        | GA4-synk                                                                                   | Akseptert |
| [0016](0016-mcp-server-og-oauth.md)             | MCP-server med egen OAuth-server, tenantvalg og skrivetilgang                              | Akseptert |
| [0017](0017-rest-api-token-og-kontrakt.md)      | REST-API: tenantbundne tokens og avgrenset kontrakt                                        | Foreslått |
| [0018](0018-veiviser-backend.md)                | Veiviserens backend: sidehenting med SSRF-vern, forslag med Sonnet, kostnadsestimat        | Akseptert |
| [0019](0019-varsler.md)                         | Varsler: evaluering, døgnmelding, levering og kvittering                                   | Foreslått |
| [0020](0020-dispatch-og-worker.md)              | Varig dispatch, køleverandører og worker                                                   | Foreslått |
| [0021](0021-fakturagrunnlag.md)                 | Månedlig fakturagrunnlag, partnerlinjer og eksplisitt påslag                               | Foreslått |
| [0022](0022-sha-bundet-kryssreview.md)          | SHA-bundet kryssreview med egen godkjenningsidentitet                                      | Foreslått |
| [0023](0023-anthropic-kredittplan.md)           | Faktureringsperioder, kredittplan og felles Anthropic-reservasjoner                        | Foreslått |

- [0024: Produksjonsdomene og direkte motorer](0024-produksjonsdomene-og-direkte-motorer.md)

- [0025: Strukturert logging og vasking av unntak](0025-strukturert-og-vasket-logging.md)
- [0026: Automatisk mergekø](0026-automatisk-mergeko.md)
- [0027: Stabil reviewkjøring og tydelige utfall](0027-stabil-reviewkjoring.md)
- [0028: Atomisk bekreftelse av veiviseren](0028-atomisk-veiviserbekreftelse.md)
- [0029: Operasjonell overvåking](0029-operasjonell-overvaking.md)
- [0030: Avgrenset worker-start og påkrevd SQL-connector](0030-avgrenset-worker-start.md)
- [0032: Varig registrering av hendelsesstyrt behandlingsarbeid](0032-varig-hendelsesstyrt-arbeid.md)
- [0033: Målrettet vekking med Cloud Tasks og reservekontroll](0033-malrettet-vekking-med-cloud-tasks.md)
- [0034: Tiltaksmotor: kilder, gjenkjenning, utløp og formulering](0034-tiltaksmotor.md)
- [0035: ECharts som grafbibliotek](0035-echarts-grafbibliotek.md)
- [0036: Fordelinger i dagsaggregatene for grafdata](0036-grafdata-fordelinger.md)
