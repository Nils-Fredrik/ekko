<?php

namespace App\Usage;

use App\Models\UsageMonthly;
use Brick\Math\BigDecimal;

final class QuotaGuard
{
    /**
     * The dispatcher passes a tenant's candidates in its preferred scheduling order. Keep
     * only core prompts when the complete schedule would overflow an applicable limit.
     *
     * @param  list<PlannedRun>  $runs
     * @return list<int> IDs to queue, in scheduling order
     */
    public function allowedRunIds(UsageMonthly $usage, array $runs): array
    {
        if ($usage->quota_calls !== null && $usage->calls >= $usage->quota_calls) {
            return [];
        }

        $feasible = array_filter($runs, function (PlannedRun $run) use ($usage): bool {
            [$used, $budget] = $this->budget($usage, $run->billingAccount);

            return $budget === null || $used->isLessThan($budget);
        });
        $platform = BigDecimal::of($usage->cost_usd);
        $byok = BigDecimal::of($usage->byok_cost_usd);
        $calls = $usage->calls;

        foreach ($feasible as $run) {
            if ($run->billingAccount === BillingAccount::Byok) {
                $byok = $byok->plus($run->estimatedCostUsd);
            } else {
                $platform = $platform->plus($run->estimatedCostUsd);
            }
        }

        $callsOverflow = $usage->quota_calls !== null && $calls + count($feasible) > $usage->quota_calls;
        $platformOverflow = $usage->budget_usd !== null && $platform->isGreaterThan($usage->budget_usd);
        $byokOverflow = $usage->byok_budget_usd !== null && $byok->isGreaterThan($usage->byok_budget_usd);
        $projected = clone $usage;
        $allowed = [];

        foreach ($feasible as $run) {
            $budgetOverflow = $run->billingAccount === BillingAccount::Byok ? $byokOverflow : $platformOverflow;

            if ((! $run->core && ($callsOverflow || $budgetOverflow)) || ! $this->allows($projected, $run)) {
                continue;
            }

            $allowed[] = $run->id;
            $projected->calls++;

            if ($run->billingAccount === BillingAccount::Byok) {
                $projected->byok_cost_usd = (string) BigDecimal::of($projected->byok_cost_usd)->plus($run->estimatedCostUsd);
            } else {
                $projected->cost_usd = (string) BigDecimal::of($projected->cost_usd)->plus($run->estimatedCostUsd);
            }
        }

        return $allowed;
    }

    public function allows(UsageMonthly $usage, PlannedRun $run): bool
    {
        if ($usage->quota_calls !== null && $usage->calls >= $usage->quota_calls) {
            return false;
        }

        [$used, $budget] = $this->budget($usage, $run->billingAccount);

        return $budget === null || (
            $used->isLessThan($budget)
            && $used->plus($run->estimatedCostUsd)->isLessThanOrEqualTo($budget)
        );
    }

    /** @return array{BigDecimal, string|null} */
    private function budget(UsageMonthly $usage, BillingAccount $account): array
    {
        return match ($account) {
            BillingAccount::Platform => [BigDecimal::of($usage->cost_usd), $usage->budget_usd],
            BillingAccount::Byok => [BigDecimal::of($usage->byok_cost_usd), $usage->byok_budget_usd],
        };
    }
}
