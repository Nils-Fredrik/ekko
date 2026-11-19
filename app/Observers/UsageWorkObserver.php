<?php

namespace App\Observers;

use App\Models\UsageEvent;
use App\Work\WorkChain;

/**
 * Every recorded usage event registers the roll-up of its UTC month (T40-F3, ADR 0032), in the transaction that
 * records it: engine calls, extraction, onboarding and reports alike. Does nothing while ekko.work.chaining is off.
 */
final class UsageWorkObserver
{
    public function created(UsageEvent $event): void
    {
        app(WorkChain::class)->usageRecorded($event);
    }
}
