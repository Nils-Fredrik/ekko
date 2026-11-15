<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Jobs\SyncGa4Integration;
use App\Models\Integration;
use App\Models\Team;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncGa4 extends Command
{
    protected $signature = 'ekko:sync-ga4';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('ga4.sync_description'));
    }

    public function handle(TenantContext $context, TenantRls $rls): int
    {
        $exitCode = self::SUCCESS;
        $teams = Team::query()->where('status', TenantStatus::Active)->select('id')->lazyById();

        foreach ($teams as $team) {
            $ids = $context->run($team->id, fn () => $rls->run([$team->id], fn () => Integration::query()
                ->where('type', 'ga4')
                ->whereRelation('brand', 'status', 'active')
                ->pluck('id')));

            foreach ($ids as $id) {
                try {
                    SyncGa4Integration::dispatchSync($team->id, $id);
                } catch (Throwable $exception) {
                    $exitCode = self::FAILURE;
                    Log::error('GA4 integration job failed.', [
                        'tenant_id' => $team->id,
                        'integration_id' => $id,
                        'exception_type' => $exception::class,
                    ]);
                }
            }
        }

        return $exitCode;
    }
}
