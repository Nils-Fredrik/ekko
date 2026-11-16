<?php

namespace App\Console\Commands;

use App\Engines\RawResponseStore;
use App\Enums\TenantStatus;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deletes tenants closed Team::PURGE_AFTER_DAYS ago, with all their data (docs/spec.md 16, T39-F6). The raw
 * answers in GCS go first: if that fails, the tenant stays and the next run tries again, so no raw answers are
 * left without a tenant. The tenant's own revision log goes with it, so the deletion is written to the
 * application log (Cloud Logging).
 */
class PurgeClosedTenants extends Command
{
    protected $signature = 'ekko:purge-closed-tenants';

    protected $description = 'Delete tenants closed 30 days ago, with their raw answers in GCS';

    public function handle(TenantContext $context, TenantRls $rls, RawResponseStore $raw): int
    {
        $failed = false;
        $due = Team::query()
            ->where('status', TenantStatus::Closed->value)
            ->whereNotNull('closed_at')
            ->where('closed_at', '<=', Date::now()->subDays(Team::PURGE_AFTER_DAYS))
            ->where('personal_team', false);

        foreach ($due->lazyById() as $tenant) {
            try {
                $objects = $raw->deleteTenant($tenant->id);
                $context->run($tenant->id, fn () => $rls->run([$tenant->id], fn () => $tenant->purge()));
                Log::info('tenant.purged', ['tenant_id' => $tenant->id, 'closed_at' => $tenant->closed_at?->toIso8601String(), 'raw_objects' => $objects]);
                $this->info("Deleted tenant {$tenant->id} and {$objects} raw answers.");
            } catch (Throwable $failure) {
                report($failure);
                $this->error("Failed to delete tenant {$tenant->id}.");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
