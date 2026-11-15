<?php

namespace App\Usage;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

final readonly class PlannedRun
{
    public BigDecimal $estimatedCostUsd;

    public function __construct(
        public int $id,
        public bool $core,
        public BillingAccount $billingAccount,
        string $estimatedCostUsd,
    ) {
        $this->estimatedCostUsd = BigDecimal::of($estimatedCostUsd);

        if ($this->estimatedCostUsd->isNegative()) {
            throw new InvalidArgumentException('Estimated cost cannot be negative.');
        }
    }
}
