<?php

namespace App\Observers;

use App\Models\UsageEvent;
use Illuminate\Support\Facades\DB;

/** Only anonymous account totals survive tenant deletion; no provider response or tenant identity. */
final class AnthropicSpendObserver
{
    public function created(UsageEvent $event): void
    {
        if ($event->billed_to === 'platform' && (in_array($event->engine_key, ['claude_haiku', 'claude_sonnet'], true) || in_array($event->kind, ['extraction', 'onboarding', 'actions'], true))) {
            DB::table('anthropic_credit_spend')->insertOrIgnore(['entry' => $event->anthropic_operation ?? 'usage:'.$event->id, 'occurred_at' => $event->occurred_at, 'cost_usd' => $event->cost_usd]);
        }
    }
}
