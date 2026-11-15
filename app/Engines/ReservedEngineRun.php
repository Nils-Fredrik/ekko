<?php

namespace App\Engines;

use App\Models\EnginePrice;
use App\Usage\BillingAccount;
use Carbon\CarbonImmutable;

final readonly class ReservedEngineRun
{
    public function __construct(
        public string $token,
        public CarbonImmutable $occurredAt,
        public BillingAccount $billingAccount,
        public EnginePrice $price,
        public EngineAdapter $adapter,
        public EngineRequest $request,
    ) {}
}
