\set ON_ERROR_STOP on
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
DO $$
BEGIN
    IF (SELECT count(*) FROM public.migrations) = 0 THEN RAISE EXCEPTION 'No restored migrations'; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' AND c.relname='brands' AND c.relrowsecurity AND c.relforcerowsecurity)
    THEN RAISE EXCEPTION 'Restored tenant schema missing'; END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns col
        JOIN pg_namespace n ON n.nspname=col.table_schema
        JOIN pg_class c ON c.relnamespace=n.oid AND c.relname=col.table_name
        WHERE col.table_schema='public' AND col.column_name='tenant_id' AND c.relkind='r'
        AND (NOT c.relrowsecurity OR NOT c.relforcerowsecurity OR NOT EXISTS (SELECT 1 FROM pg_policy p WHERE p.polrelid=c.oid)))
    THEN RAISE EXCEPTION 'Restored tenant RLS incomplete'; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='ekko_app' AND NOT rolsuper AND NOT rolbypassrls AND NOT rolcreaterole AND NOT rolcreatedb AND NOT rolreplication)
    THEN RAISE EXCEPTION 'Unsafe restored app role'; END IF;
    IF EXISTS (SELECT 1 FROM pg_auth_members m JOIN pg_roles r ON r.oid=m.member WHERE r.rolname='ekko_app')
    THEN RAISE EXCEPTION 'Unexpected restored app role membership'; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname=current_user AND rolbypassrls)
    THEN RAISE EXCEPTION 'Recovery verifier needs migration role'; END IF;
END $$;
CREATE TABLE public.ekko_restore_probe (tenant_id bigint NOT NULL, marker text NOT NULL);
GRANT SELECT ON public.ekko_restore_probe TO ekko_app;
INSERT INTO public.ekko_restore_probe VALUES (101, 'synthetic-a'), (102, 'synthetic-b');
ALTER TABLE public.ekko_restore_probe ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.ekko_restore_probe FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON public.ekko_restore_probe
 USING (tenant_id = ANY(string_to_array(current_setting('app.tenant_ids', true), ',')::bigint[]));
SET LOCAL ROLE ekko_app;
SELECT set_config('app.tenant_ids', '', true);
DO $$ BEGIN
 IF (SELECT count(*) FROM public.ekko_restore_probe) <> 0 THEN RAISE EXCEPTION 'RLS without tenant failed'; END IF;
END $$;
SELECT set_config('app.tenant_ids', '101', true);
DO $$ BEGIN
 IF (SELECT count(*) FROM public.ekko_restore_probe) <> 1
 OR (SELECT marker FROM public.ekko_restore_probe) <> 'synthetic-a'
 THEN RAISE EXCEPTION 'Tenant A isolation failed'; END IF;
END $$;
SELECT set_config('app.tenant_ids', '102', true);
DO $$ BEGIN
 IF (SELECT count(*) FROM public.ekko_restore_probe) <> 1
 OR (SELECT marker FROM public.ekko_restore_probe) <> 'synthetic-b'
 THEN RAISE EXCEPTION 'Tenant B isolation failed'; END IF;
END $$;
RESET ROLE;
DO $$ BEGIN
 IF (SELECT count(*) FROM public.ekko_restore_probe) <> 2 THEN RAISE EXCEPTION 'Migration role failed'; END IF;
END $$;
ROLLBACK;
