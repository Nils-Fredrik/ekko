# Arkitekturbeslutninger (ADR)

Beslutninger som ikke står i `docs/spec.md`, dokumenteres her (`AGENTS.md`, `docs/spec.md` 19.2).

- Én fil per beslutning: `NNNN-kort-tittel.md`, fortløpende nummerert.
- Malen: [0000-mal.md](0000-mal.md).
- En beslutning endres ikke i ettertid. Den erstattes av en ny ADR, og den gamle får status «Erstattet av NNNN».

| Nr | Tittel | Status |
|---|---|---|
| [0001](0001-app-i-rotmappen-og-testdatabase.md) | Laravel-appen i rotmappen og egen testdatabase per worktree | Akseptert |
| [0002](0002-tenant-kontekst.md) | Tenant-kontekst og regler for tenant-eide modeller | Akseptert |
| [0003](0003-tenant-eide-tabellrelasjoner.md) | Fremmednøkler for tenant-eide data | Akseptert |
