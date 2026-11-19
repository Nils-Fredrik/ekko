<?php

namespace App\Billing;

use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\User;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use App\Usage\PlatformPaidUsage;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class BillingStatement
{
    public function __construct(private TenantContext $context, private TenantRls $rls) {}

    /** @return list<array<string, mixed>> */
    public function rows(User $admin, string $month): array
    {
        Gate::allowIf($admin->is_platform_admin);
        if (! preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $month)) {
            throw ValidationException::withMessages(['month' => __('billing.invalid_month')]);
        }
        $from = CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC');
        $to = $from->addMonth();
        $rows = [];
        // Closed tenants can still have billable usage in the requested month.
        foreach (Team::query()->where('personal_team', false)->with(['plan', 'partner'])->orderByRaw('coalesce(partner_team_id, id)')->orderBy('id')->get() as $tenant) {
            // Usage the platform pays for (ADR 0034) is not invoiced to the tenant.
            $events = $this->context->run($tenant->id, fn () => $this->rls->run([$tenant->id], fn () => PlatformPaidUsage::exclude(UsageEvent::query())
                ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)
                ->selectRaw('engine_key, billed_to, sum(calls) AS calls, sum(cost_usd) AS cost_usd')
                ->groupBy('engine_key', 'billed_to')->get()
                ->map(fn (UsageEvent $event): array => ['engine_key' => $event->engine_key, 'billed_to' => $event->billed_to, 'calls' => (int) $event->calls, 'cost_usd' => $event->cost_usd])->all()));
            if ($events === []) {
                continue;
            }
            $account = $tenant->partner ?? $tenant;
            $rows[] = [
                'month' => $month, 'account_id' => $account->id, 'account_name' => $account->name,
                'tenant_id' => $tenant->id, 'tenant_name' => $tenant->name,
                'plan' => $tenant->plan->name ?? '',
            ] + BillingTotals::summarize($events, $tenant->billing_markup_percent);
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<array{id: int, name: string, invoice_total_usd: string|null}>
     */
    public function accounts(array $rows): array
    {
        $accounts = [];
        foreach ($rows as $row) {
            $id = $row['account_id'];
            $accounts[$id] ??= ['id' => $id, 'name' => $row['account_name'], 'invoice_total_usd' => '0.00'];
            $previous = $accounts[$id]['invoice_total_usd'];
            $accounts[$id]['invoice_total_usd'] = $previous === null || $row['invoice_total_usd'] === null
                ? null : (string) BigDecimal::of($previous)->plus($row['invoice_total_usd'])->toScale(2);
        }

        return array_values($accounts);
    }
}
