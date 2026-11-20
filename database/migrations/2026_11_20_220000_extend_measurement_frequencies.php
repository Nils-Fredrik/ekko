<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $frequencies = "'off', 'monthly', 'every_21_days', 'every_14_days', 'weekly', 'daily'";
        $this->replaceCheck('market_engines', 'frequency', $frequencies);
        $this->replaceCheck('plans', 'max_frequency', $frequencies);
    }

    public function down(): void
    {
        // The old application cannot read these package overrides. Preserve them until an owner changes them.
        if (DB::table('plans')->whereRaw(<<<'SQL'
            jsonb_path_exists(engine_max_frequencies, '$.* ? (@ == "off" || @ == "every_14_days" || @ == "every_21_days")')
            SQL)->exists()) {
            throw new RuntimeException('Change unsupported engine frequency overrides before rolling back.');
        }

        $this->replaceCheck('market_engines', 'frequency', "'daily', 'weekly', 'monthly', 'off'");
        $this->replaceCheck('plans', 'max_frequency', "'daily', 'weekly', 'monthly'");
    }

    private function replaceCheck(string $table, string $column, string $values): void
    {
        $constraint = "{$table}_{$column}_check";
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}, ADD CONSTRAINT {$constraint} CHECK ({$column} IN ({$values}))");
    }
};
