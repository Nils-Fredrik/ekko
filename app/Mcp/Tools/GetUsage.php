<?php

namespace App\Mcp\Tools;

use App\Enums\TenantPermission;
use App\Mcp\EkkoTool;
use App\Mcp\McpContext;
use App\Mcp\ToolFailure;
use App\Mcp\ToolResult;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Models\UsageMonthly;
use App\Usage\MonthlyUsage;
use App\Usage\PlatformPaidUsage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('get_usage')]
#[Description('Forbruket i en måned (UTC-kalendermåned, som kvoten) mot kvoten og budsjettene: kall, plattformkostnad mot pakkens budsjett og kostnad på egne nøkler (BYOK) mot eget budsjett, fordelt på type (motorkall, uttrekk, onboarding, PDF, tiltak), motor og betaler, og status (ok, advarsel, stoppet). Typer plattformen betaler (platform_paid, f.eks. formulering av tiltak) telles ikke mot kvoten og budsjettet. Krever rettigheten til å se forbruk (tenant-admin).')]
#[IsReadOnly]
class GetUsage extends EkkoTool
{
    protected ?TenantPermission $permission = TenantPermission::ViewUsage;

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->tenantArgument($schema),
            'month' => $schema->string()->description('Måneden (ÅÅÅÅ-MM). Standard: inneværende måned.'),
        ];
    }

    protected function run(Request $request, McpContext $context, Team $tenant): ToolResult
    {
        // Usage months are UTC calendar months, as in the quota and the rollup (ADR 0013).
        $month = $this->month($request->get('month'));
        $stored = UsageMonthly::query()->whereDate('month', $month->format('Y-m-d'))->first();
        $usage = app(MonthlyUsage::class)->snapshot($tenant, $month, $stored);

        $events = UsageEvent::query()
            ->where('occurred_at', '>=', $month)
            ->where('occurred_at', '<', $month->addMonth())
            ->toBase()
            ->selectRaw('kind, engine_key, billed_to, sum(calls) as calls, sum(cost_usd) as cost_usd')
            ->groupBy('kind', 'engine_key', 'billed_to')->orderBy('kind')->orderBy('engine_key')->orderBy('billed_to')
            ->get()
            ->map(fn (object $row): array => [
                'kind' => (string) $row->kind,
                'engine' => $row->engine_key,
                'billed_to' => (string) $row->billed_to,
                'calls' => (int) $row->calls,
                'cost_usd' => round((float) $row->cost_usd, 4),
                // Paid by the platform and left out of the calls and costs above (ADR 0034).
                'platform_paid' => PlatformPaidUsage::isPlatformPaid((string) $row->kind),
            ])->values()->all();

        $plan = $tenant->plan;
        $nullable = fn (mixed $value): ?float => $value === null ? null : (float) $value;

        return new ToolResult(
            __('mcp.summaries.get_usage', ['month' => $month->format('Y-m'), 'calls' => $usage->calls, 'quota' => $usage->quota_calls ?? '–']),
            [
                'month' => $month->format('Y-m'),
                'plan' => $plan === null ? null : ['name' => $plan->name, 'monthly_call_quota' => $plan->monthly_call_quota, 'monthly_budget_usd' => $nullable($plan->monthly_budget_usd)],
                'calls' => (int) $usage->calls,
                'quota_calls' => $usage->quota_calls === null ? null : (int) $usage->quota_calls,
                // Platform cost counts against the plan's budget; costs on the tenant's own keys (BYOK) against its own.
                'platform_cost_usd' => $nullable($usage->cost_usd),
                'budget_usd' => $nullable($usage->budget_usd),
                'byok_cost_usd' => $nullable($usage->byok_cost_usd),
                'byok_budget_usd' => $nullable($usage->byok_budget_usd),
                'status' => $usage->status,
                'by_kind_engine_and_payer' => $events,
            ],
        );
    }

    private function month(mixed $value): CarbonImmutable
    {
        if ($value === null || $value === '') {
            return CarbonImmutable::now('UTC')->startOfMonth();
        }
        try {
            $month = is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $value, 'UTC') : null;
        } catch (Throwable) {
            $month = null;
        }
        if ($month === null || $month->format('Y-m') !== $value) {
            throw new ToolFailure(__('mcp.errors.month'));
        }

        return $month->startOfMonth();
    }
}
