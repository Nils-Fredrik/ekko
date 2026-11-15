<?php

namespace App\AnthropicBudget;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** Platform account with short admission transactions and durable per-attempt identities. */
final class Account
{
    public function __construct(private ?string $connection = null) {}

    public static function independent(): self
    {
        config(['database.connections.anthropic_budget' => DB::connection()->getConfig()]);

        return new self('anthropic_budget');
    }

    public function db(): Connection
    {
        return DB::connection($this->connection);
    }

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
        return Money::units((string) $this->db()->table('anthropic_credit_spend')->where('occurred_at', '>=', $cycle->start)->where('occurred_at', '<', $cycle->end)->sum('cost_usd'));
    }

    public function exhausted(Cycle $cycle): bool
    {
        return (bool) $this->db()->table('anthropic_budget_cycles')->where('cycle_start', $cycle->start->toDateString())->value('exhausted');
    }

    public function markExhausted(): void
    {
        if (! $this->configured()) {
            return;
        }
        $this->db()->table('anthropic_budget_cycles')->upsert([['cycle_start' => $this->cycle()->start->toDateString(), 'exhausted' => true]], ['cycle_start'], ['exhausted']);
    }

    public function reserve(string $operation, int $estimate, bool $allowedInReducedMode): bool
    {
        if ($estimate < 0) {
            throw new InvalidArgumentException('Negative reservation');
        }
        if (! $this->configured()) {
            return true;
        }
        // A wait on the old row must never admit a new-period call against the old account.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $cycle = $this->cycle();
            $result = $this->db()->transaction(function () use ($cycle, $operation, $estimate, $allowedInReducedMode): ?bool {
                $this->db()->table('anthropic_budget_cycles')->insertOrIgnore(['cycle_start' => $cycle->start->toDateString()]);
                $row = $this->db()->table('anthropic_budget_cycles')->where('cycle_start', $cycle->start->toDateString())->lockForUpdate()->first();
                if (! $cycle->start->equalTo($this->cycle()->start)) {
                    return null;
                }
                $spent = $this->spent($cycle);
                $held = Money::units((string) $this->db()->table('anthropic_budget_holds')->where('cycle_start', $cycle->start->toDateString())->whereNull('settled_at')->sum('cost_usd'));
                if ($row->exhausted || (! $allowedInReducedMode && $spent >= intdiv($this->pool() * 95, 100)) || $spent + $held + $estimate > $this->pool()) {
                    return false;
                }
                $this->db()->table('anthropic_budget_holds')->insert(['operation' => $operation, 'cycle_start' => $cycle->start->toDateString(), 'cost_usd' => Money::usd($estimate)]);

                return true;
            });
            if ($result !== null) {
                return $result;
            }
        }
        throw new RuntimeException('Billing cycle changed during admission');
    }

    /** Known unbilled rejection, or usage already recorded in this connection's transaction. */
    public function settle(string $operation): void
    {
        if (! $this->configured()) {
            return;
        }
        $this->db()->transaction(function () use ($operation): void {
            $this->lockCurrent();
            $this->db()->table('anthropic_budget_holds')->where('operation', $operation)->update(['settled_at' => CarbonImmutable::now('UTC')]);
        });
    }

    /** Provider outcome is durable even if the caller's tenant transaction later rolls back. */
    public function settleKnown(string $operation, string $cost): void
    {
        $this->db()->transaction(function () use ($operation, $cost): void {
            $this->lockCurrent();
            $this->db()->table('anthropic_credit_spend')->insertOrIgnore(['entry' => $operation, 'occurred_at' => CarbonImmutable::now('UTC'), 'cost_usd' => $cost]);
            $this->db()->table('anthropic_budget_holds')->where('operation', $operation)->update(['settled_at' => CarbonImmutable::now('UTC')]);
        });
    }

    private function lockCurrent(): void
    {
        $day = $this->cycle()->start->toDateString();
        $this->db()->table('anthropic_budget_cycles')->insertOrIgnore(['cycle_start' => $day]);
        $this->db()->table('anthropic_budget_cycles')->where('cycle_start', $day)->lockForUpdate()->first();
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        if (! $this->configured()) {
            return ['configured' => false];
        }
        $cycle = $this->cycle();
        $row = $this->db()->table('anthropic_budget_cycles')->where('cycle_start', $cycle->start->toDateString())->first();
        $spent = $this->spent($cycle);

        return ['configured' => true, 'start' => $cycle->start->toDateString(), 'end' => $cycle->end->toDateString(),
            'pool_usd' => Money::usd($this->pool()), 'spent_usd' => Money::usd($spent),
            'reduced' => $this->exhausted($cycle) || $spent >= intdiv($this->pool() * 95, 100),
            'exhausted' => $this->exhausted($cycle), 'planned_at' => $row?->planned_at,
            'forecast' => $row?->forecast === null ? null : json_decode($row->forecast, true)];
    }
}
