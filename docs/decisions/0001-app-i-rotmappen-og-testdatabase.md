# 0001 – Laravel-appen i rotmappen og egen testdatabase per worktree

- **Status:** Akseptert
- **Dato:** 2026-11-14
- **Board:** `task:1` / T01

## Kontekst

Varde har Laravel-appen i undermappen `web/`. Spesifikasjonen sier at Ekko skal ha «samme versjoner og struktur som Varde», men `AGENTS.md` og 19.6 forutsetter at `composer test` og `npm run lint` kjøres i rotmappen til hver worktree, og at `infra/terraform`, `docs/` og `scripts/` ligger i rotmappen.

Tre agenter kjører tester samtidig mot én felles lokal Postgres-server. Laravels standardoppsett for tester (SQLite i minnet) gir ikke Postgres-funksjoner som RLS (T04), og en delt testdatabase gjør at `RefreshDatabase` i én worktree sletter data for en annen.

## Beslutning

1. **Laravel-appen ligger i rotmappen.** Strukturen inne i appen (`app/`, `resources/js/{Components,Layouts,Pages}`, `lang/`) følger Varde.
2. **Testene kjører mot PostgreSQL.** `phpunit.xml` setter `DB_CONNECTION=pgsql`.
3. **Egen testdatabase per worktree.** Når `APP_ENV=testing`, bruker `config/database.php` `DB_TEST_DATABASE` (standard `ekko_testing`, som CI bruker) i stedet for `DB_DATABASE`. Hver worktree setter `DB_TEST_DATABASE=<database>_testing` i `.env`. Testene kan dermed aldri treffe utviklingsdatabasen. `tests/Feature/FoundationTest.php` kontrollerer at databasen er Postgres og slutter på `_testing`.
4. **Versjoner:** samme hovedversjoner som Varde (Laravel 12, Jetstream 5, Inertia 2, Vue 3.5, Vite 7, Tailwind 4, Pest 4). Låsefilene er laget på nytt, slik at Ekko ikke drar med seg Varde-spesifikke pakker.
5. **I tillegg til Varde:** Larastan nivå 6, ESLint og Prettier, som spesifikasjonen krever (kapittel 4 og 18). Varde har ikke disse.

## Konsekvenser

- Dockerfile og CI (T10) bygger fra rotmappen, ikke fra `web/`.
- CI må ha en Postgres-tjeneste med databasen `ekko_testing`.
- Nye agenter og skyøkter setter opp databasene med `scripts/agent-setup.sh`.
