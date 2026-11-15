<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_events', function (Blueprint $table): void {
            $table->uuid('collection_attempt_token')->nullable()->unique();
        });
        Schema::table('run_batches', function (Blueprint $table): void {
            $table->date('dispatch_date')->nullable();
            $table->unique(['tenant_id', 'market_id', 'dispatch_date']);
        });
        Schema::table('runs', function (Blueprint $table): void {
            $table->timestampTz('enqueued_at')->nullable();
            $table->string('provider_task_id')->nullable();
            $table->text('provider_api_key')->nullable();
            $table->string('provider_billed_to')->nullable();
            $table->timestampTz('provider_submitted_at')->nullable();
            $table->unsignedSmallInteger('provider_submissions')->default(0);
            $table->foreignId('provider_price_id')->nullable()->constrained('engine_prices');
            $table->timestampTz('next_poll_at')->nullable();
            $table->index(['tenant_id', 'status', 'enqueued_at', 'next_poll_at'], 'runs_dispatch_pending');
        });
    }

    public function down(): void
    {
        Schema::table('usage_events', fn (Blueprint $table) => $table->dropColumn('collection_attempt_token'));
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropIndex('runs_dispatch_pending');
            $table->dropConstrainedForeignId('provider_price_id');
            $table->dropColumn(['enqueued_at', 'provider_task_id', 'provider_api_key', 'provider_billed_to', 'provider_submitted_at', 'provider_submissions', 'next_poll_at']);
        });
        Schema::table('run_batches', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'market_id', 'dispatch_date']);
            $table->dropColumn('dispatch_date');
        });
    }
};
