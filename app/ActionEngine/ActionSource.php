<?php

namespace App\ActionEngine;

use App\Enums\ActionType;

/**
 * A source of actions (docs/spec.md 11.3). Registered in config('ekko.actions.sources'); runs in the tenant's
 * context under row-level security and reads figures through MetricsService or the tenant's own tables.
 */
interface ActionSource
{
    public function type(): ActionType;

    public function evaluate(GenerationContext $context): SourceOutcome;
}
