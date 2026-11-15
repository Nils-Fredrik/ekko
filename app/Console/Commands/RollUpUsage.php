<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Usage\UsageRollup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class RollUpUsage extends Command
{
    protected $signature = 'ekko:usage-rollup';

    protected $description = 'Update monthly usage and quota alerts for active and paused tenants';

    public function handle(TenantContext $context, TenantRls $rls, UsageRollup $rollup): int
    {
        $now = CarbonImmutable::now('UTC');
        $failed = false;

        foreach (Team::query()->where('status', '<>', TenantStatus::Closed->value)->select('id')->lazyById() as $tenant) {
            try {
                $context->run($tenant->id, fn () => $rls->run([$tenant->id], fn () => $rollup->refresh($now)));
            } catch (Throwable $failure) {
                report($failure);
                $this->error("Failed to roll up usage for tenant {$tenant->id}.");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
