<?php

namespace Tests\Fixtures;

use App\Jobs\TenantJob;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reports what a tenant job can see without the Eloquent tenant scope, and optionally tries a raw
 * insert into another tenant.
 */
class ReadTenantRecordsJob extends TenantJob
{
    public function __construct(int $tenantId, public readonly ?int $rawInsertTenantId = null)
    {
        parent::__construct($tenantId);
    }

    public function handle(): void
    {
        Cache::put('rls-job-result', [
            'eloquent_unscoped' => TenantRecord::withoutGlobalScope(TenantScope::class)->pluck('tenant_id')->all(),
            'raw' => DB::table('test_tenant_records')->pluck('tenant_id')->all(),
        ]);

        if ($this->rawInsertTenantId !== null) {
            DB::table('test_tenant_records')->insert([
                'tenant_id' => $this->rawInsertTenantId,
                'name' => 'raw',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
