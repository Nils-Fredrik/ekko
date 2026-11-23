# 0046 Arkivering av merkevarer

`brands.status` får verdien `archived`. Kolonnen er allerede tekst, og arkivering beholder merkevarens ID, navn, markeder, historiske svar og rapporter. En merkevare på pause er fortsatt synlig og kan gjenopptas. En arkivert merkevare fjernes fra velgerne og frigjør en plass i pakkens merkevaregrense. Sluggen forblir reservert.

`POST /brands/{brand}/archive` krever `brands.manage`. Serveren låser raden og krever at det innskrevne navnet er identisk med det gjeldende navnet. Vanlige identitetsendringer kan ikke gjenåpne en arkivert merkevare.

Planleggeren velger aktive merkevarer. Innsamlerne kontrollerer også merkevarens status ved reservasjon, før en bestilling eller ny betalt innsending. En måling som allerede er bestilt, får fullføre og bokføres på vanlig måte.

Planlagte rapporter og nye månedsinnsikter stopper. Allerede lagrede rapporter er fortsatt tilgjengelige i rapportarkivet. Innsamlede svar beholdes i databasen. Merkevarens egne arbeidsflater stenges. Innboksen skjuler varsler som peker til en arkivert merkevare. En allerede betalt køoppgave kan hentes ut i det ordinære tolvtimersvinduet.

Arkiveringen lagrer samtidig tidspunktet i `brands.archived_at`. For en tidligere startet måling beholder både innsending og ferdigbehandling av uttrekk merkevarens alias, domener og konkurrenter i gjenkjenningskatalogen. Målinger som starter etter arkiveringen får dagens katalog. Denne avgrensningen gjelder hvert svar når en batch inneholder flere merkevarer.
