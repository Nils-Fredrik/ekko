# 0025 – Strukturert logging og vasking av unntak

- Status: Foreslått
- Dato: 2026-11-17
- Board: task:56 (T39-F7); forutsetning for task:36 (T36)

## Bakgrunn

Produksjonens stderr-kanal brukte linjetekst og debug som standard. Laravels vanlige rapportering tar med unntaksmeldinger, custom context og tidligere unntak. QueryException kan inkludere SQL-bindings, og SMTP-/HTTP-feil kan inneholde kundedata eller hemmeligheter. En formatter alene fjerner ikke disse opplysningene. Artisan kan dessuten skrive det opprinnelige unntaket etter rapporteringen.

## Beslutning

Bruk Monologs GoogleCloudLoggingFormatter på stderr, med info som standard og eksplisitt LOG_LEVEL=info i Terraform. Feltene for tenant, bruker og run ligger i context. En scoped LogContext deler bare interne heltalls-ID-er gjennom Log::shareContext. HTTP-middleware nullstiller ved inngang og i finally. Eksisterende autentiserings-/tenantmiddleware setter bruker og tenant først når de er kjent. TenantContext dekker også MCP-verktøy og synkrone tenantoperasjoner. TenantJob-middleware setter tenant, eventuell run-ID og user_id=null. Nestede scopes gjenoppretter konteksten i finally.

Når et scope kaster, lagres ID-ene i en WeakMap knyttet til akkurat det unntaket. Innerste scope vinner. Report-kallet kan dermed skje etter rollback/opprydding uten å tilordne feilen til neste tenant. Kartet holder ikke liv i unntaket og resettes med containerens scope.

En report-callback i withExceptions erstatter standardrapporteringen for alle rapporterbare Throwable, også ukjente wrappers. Den logger en fast melding og eksplisitt utvalgte kodesteder og klassenavn. Meldinger, bindings, custom context, argumenter og objekter fra stacken brukes ikke. Stderr har i tillegg en processor for Throwable i direkte PSR-loggkontekst. Vanlige loggmeldinger må fortsatt bruke trygge, statiske tekster og metadata; denne kontrollen kan ikke gjenkjenne enhver hemmelighet i en vilkårlig streng.

Produksjonsimaget setter zend.exception_ignore_args=On. En smal utvidelse av Laravels exception handler skriver fast konsolltekst i produksjon. HTTP-rendering, rapporterbare feil og exit-status beholder rammeverkets oppførsel. Utviklingskonsollen beholder vanlig feilsøking; lokal report()-logging vaskes også.

## Konsekvenser og verifikasjon

Feilsøking må bruke feiltype, kodested og interne ID-er; den rå leverandør-/databaseforklaringen finnes ikke i produksjonsloggen. Dette er et bevisst personvernvalg, ikke et avvik fra spec 16. Ingen ruter, MCP-tools eller forretningsrettigheter endres.

Pest tester JSON/severity, SQL-bindings, SMTP-debug, exception-kjeder, custom context og stackargumenter, samt sekvensielle requests og køjobber, nestede tenants og feil etter scope-opprydding. Containerens eksisterende verifikasjon tester begge no-dev-images med en ekte feilende Artisan-kommando. T36 må bruke de dokumenterte JSON-feltene.

Kilder: [Cloud Logging: structured logging](https://docs.cloud.google.com/logging/docs/structured-logging), [Laravel 12: exception reporting](https://laravel.com/docs/12.x/errors#reporting-exceptions). API og formatter er også kontrollert mot installerte composer.lock-versjoner.
