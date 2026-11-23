# 0046 Arkivering av merkevarer

`brands.status` får verdien `archived`. Kolonnen er allerede tekst, og arkivering beholder merkevarens ID, navn, markeder, historiske svar og rapporter. En merkevare på pause er fortsatt synlig og kan gjenopptas. En arkivert merkevare fjernes fra velgerne og frigjør en plass i pakkens merkevaregrense. Sluggen forblir reservert.

`POST /brands/{brand}/archive` krever `brands.manage`. Serveren låser raden og krever at det innskrevne navnet er identisk med det gjeldende navnet. Vanlige identitetsendringer kan ikke gjenåpne en arkivert merkevare.

Planleggeren velger aktive merkevarer. Innsamlerne kontrollerer også merkevarens status ved reservasjon, før en bestilling eller ny betalt innsending. En måling som allerede er bestilt, får fullføre og bokføres på vanlig måte.
