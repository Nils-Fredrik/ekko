<?php

namespace App\AnthropicBudget;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Internal platform account: returns aggregate dollars only, never another tenant's records. */
final class Account
{
    public function configured(): bool
    {
        return filled(config('ekko.anthropic.monthly_pool_usd'));
    }

    public function cycle(?CarbonImmutable $at = null): Cycle
    {
        return Cycle::at($at ?? CarbonImmutable::now('UTC'), (int) config('ekko.anthropic.cycle_start_day'));
    }

    public function pool(): int
    {
        return Money::units((string) config('ekko.anthropic.monthly_pool_usd'));
    }

    public function spent(Cycle $cycle): int
    {
        return Money::units((string) DB::table('anthropic_credit_spend')->where('occurred_at', '>=', $cycle->start)->where('occurred_at', '<', $cycle->end)->sum('cost_usd'));
    }

    public function exhausted(Cycle $cycle): bool
    {
        return (bool) DB::table('anthropic_budget_cycles')->where('cycle_start', $cycle->start->toDateString())->value('exhausted');
    }

    public function markExhausted(): void
    {
        if (! $this->configured()) {
            return;
        }
        $day = $this->cycle()->start->toDateString();
        DB::table('anthropic_budget_cycles')->upsert([['cycle_start' => $day, 'exhausted' => true]], ['cycle_start'], ['exhausted']);
    }

    /** Serialize admission across workers; pending batches occupy their estimated capacity until settled. */
    public function reserve(string $operation, int $estimate, bool $allowedInReducedMode): bool
    {
        if (! $this->configured()) {
            return true;
        }
        $cycle = $this->cycle();

        return DB::transaction(function () use ($cycle, $operation, $estimate, $allowedInReducedMode): bool {
            DB::table('anthropic_budget_cycles')->insertOrIgnore(['cycle_start' => $cycle->start->toDateString()]);
            $row = DB::table('anthropic_budget_cycles')->where('cycle_start', $cycle->start->toDateString())->lockForUpdate()->first();
            $spent = $this->spent($cycle);
            $held = Money::units((string) DB::table('anthropic_budget_holds')->where('cycle_start', $cycle->start->toDateString())->sum('cost_usd'));
            if ($row->exhausted || (! $allowedInReducedMode && $spent >= intdiv($this->pool() * 95, 100)) || $spent + $held + $estimate > $this->pool()) {
                return false;
            }
            $prior = Money::units((string) (DB::table('anthropic_budget_holds')->where('cycle_start', $cycle->start->toDateString())->where('operation', $operation)->value('cost_usd') ?? '0'));
            DB::table('anthropic_budget_holds')->upsert([['operation' => $operation, 'cycle_start' => $cycle->start->toDateString(), 'cost_usd' => Money::usd($prior + $estimate)]], ['cycle_start', 'operation'], ['cost_usd']);

            return true;
        });
    }

    public function settle(string $operation): void
    {
        if ($this->configured()) {
            DB::transaction(function () use ($operation): void {
                DB::table('anthropic_budget_cycles')->where('cycle_start', $this->cycle()->start->toDateString())->lockForUpdate()->first();
                DB::table('anthropic_budget_holds')->where('operation', $operation)->delete();
            });
        }
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        if (! $this->configured()) {
            return ['configured' => false];
        }
        $cycle = $this->cycle();
        $row = DB::table('anthropic_budget_cycles')->where('cycle_start', $cycle->start->toDateString())->first();
        $spent = $this->spent($cycle);

        return ['configured' => true, 'start' => $cycle->start->toDateString(), 'end' => $cycle->end->toDateString(),
            'pool_usd' => Money::usd($this->pool()), 'spent_usd' => Money::usd($spent),
            'reduced' => $this->exhausted($cycle) || $spent >= intdiv($this->pool() * 95, 100),
            'exhausted' => $this->exhausted($cycle), 'planned_at' => $row?->planned_at,
            'forecast' => $row?->forecast === null ? null : json_decode($row->forecast, true)];
    }
}
