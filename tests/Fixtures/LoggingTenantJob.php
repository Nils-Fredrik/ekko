<?php

namespace Tests\Fixtures;

use App\Jobs\TenantJob;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class LoggingTenantJob extends TenantJob
{
    public function __construct(int $tenantId, public bool $throw = false)
    {
        parent::__construct($tenantId);
    }

    public function handle(): void
    {
        Log::info('job.test');
        if ($this->throw) {
            throw new RuntimeException('synthetic-job-secret');
        }
    }
}
