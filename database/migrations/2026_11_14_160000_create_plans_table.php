<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Packages defined by the platform admin (docs/spec.md 6.1 and 12.1).
     * A null limit means unlimited, or set per tenant for the Byrå package.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('max_brands')->nullable();
            $table->unsignedInteger('max_markets_per_brand')->nullable();
            $table->unsignedInteger('max_prompts_per_market')->nullable();
            $table->jsonb('allowed_engines')->default('[]');
            $table->string('max_frequency')->default('daily');
            $table->unsignedSmallInteger('max_repetitions')->default(1);
            $table->unsignedInteger('monthly_call_quota')->nullable();
            $table->decimal('monthly_budget_usd', 12, 2)->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_max_frequency_check CHECK (max_frequency IN ('daily', 'weekly', 'monthly'))");
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_max_repetitions_check CHECK (max_repetitions BETWEEN 1 AND 3)');
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
