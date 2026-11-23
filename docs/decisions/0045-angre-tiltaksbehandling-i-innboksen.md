# 0045 Angre behandling av tiltak i innboksen

Innboksen lar en redaktør angre Planlegg, Avvis og «Lag tiltak» fra meldingen i åtte sekunder. Den vanlige statusflyten tillater bare genereringen å sette et tiltak til nytt.

Ved behandling låser serveren tiltaket og utsteder en tilfeldig kvittering. Den delte cachen beholder endrede felt og eventuelle tidligere promptkoblinger i fem minutter for å tåle nettverksforsinkelse. Kvitteringen gjelder én bruker, tenant og tiltak. Nettleseren får tiltakets ID og kvitteringens nøkkel.

Ved angre kontrollerer serveren dagens rettighet og låser raden på nytt. PostgreSQLs transaksjons-ID og radplassering (`xmin`, `ctid`) identifiserer radversjonen gjennom den korte angrefristen. Sammen med gjeldende promptkoblinger hindrer dette at angre overskriver senere arbeid. Hvis versjonen har endret seg, svarer serveren med HTTP 409. En bestilling som opprettet et nytt tiltak kan fjerne det. For et eksisterende tiltak gjenopprettes bare feltene og koblingene bestillingen endret.
