<?php

namespace App\Reports;

use App\Models\EnginePrice;

/** A monthly Sonnet allowance that this report run may spend once (ADR 0023). */
final class InsightAdmission
{
    public function __construct(
        public readonly int $allocationId,
        public readonly int $brandId,
        public readonly string $cycleStart,
        public readonly string $operation,
        public readonly ?string $overageOperation,
        public readonly EnginePrice $price,
    ) {}
}
