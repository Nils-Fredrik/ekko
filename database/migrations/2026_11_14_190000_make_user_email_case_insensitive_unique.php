<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * E-mail addresses are stored lower case and unique regardless of case (ADR 0006). Duplicates
     * that differ only in case are never merged automatically: the migration stops and says which.
     */
    public function up(): void
    {
        $duplicates = DB::select('SELECT lower(trim(email)) AS email, count(*) AS n FROM users GROUP BY lower(trim(email)) HAVING count(*) > 1');

        if ($duplicates !== []) {
            $list = implode(', ', array_map(fn ($row) => "{$row->email} ({$row->n})", $duplicates));

            throw new RuntimeException("Users with e-mail addresses that differ only in case must be resolved by hand first: {$list}");
        }

        DB::statement('UPDATE users SET email = lower(trim(email)) WHERE email <> lower(trim(email))');
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    }
};
