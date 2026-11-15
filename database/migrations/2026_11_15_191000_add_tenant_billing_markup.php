<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', fn (Blueprint $table) => $table->decimal('billing_markup_percent', 9, 4)->nullable());
        DB::statement('ALTER TABLE teams ADD CONSTRAINT teams_billing_markup_nonnegative CHECK (billing_markup_percent >= 0)');
    }

    public function down(): void
    {
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('billing_markup_percent'));
    }
};
