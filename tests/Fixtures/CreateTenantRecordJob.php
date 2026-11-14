<?php

namespace Tests\Fixtures;

use App\Jobs\TenantJob;

class CreateTenantRecordJob extends TenantJob
{
    public function __construct(int $tenantId, public readonly string $name, public readonly ?int $explicitTenantId = null)
    {
        parent::__construct($tenantId);
    }

    public function handle(): void
    {
        TenantRecord::create(array_filter([
            'name' => $this->name,
            'tenant_id' => $this->explicitTenantId,
        ], fn ($value) => $value !== null));
    }
}
