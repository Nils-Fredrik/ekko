<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * E-mail addresses are stored lower case and unique regardless of case (ADR 0006). Duplicates
     * that differ only in case are never merged automatically: the migration stops and names the
     * user ids. The addresses themselves are left out, so no personal data ends up in deploy logs.
     */
    public function up(): void
    {
        $duplicates = DB::select("SELECT string_agg(id::text, '+' ORDER BY id) AS ids FROM users GROUP BY lower(trim(email)) HAVING count(*) > 1");

        if ($duplicates !== []) {
            $list = implode(', ', array_map(fn ($row) => (string) $row->ids, $duplicates));

            throw new RuntimeException("Users whose e-mail addresses differ only in case must be resolved by hand first (user ids): {$list}");
        }

        DB::statement('UPDATE users SET email = lower(trim(email)) WHERE email <> lower(trim(email))');
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
    }

    /**
     * Only drops the index. Addresses already lower-cased by up() are not restored, since the
     * original case is gone.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    }
};
