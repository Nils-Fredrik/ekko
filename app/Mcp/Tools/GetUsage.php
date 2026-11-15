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
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('get_usage')]
#[Description('Forbruket i en måned mot kvoten og budsjettet i pakken: kall og kostnad i USD, fordelt på type (motorkall, uttrekk, onboarding, PDF) og motor, og status (ok, advarsel, stoppet). Krever rettigheten til å se forbruk (tenant-admin).')]
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
        $timezone = $this->timezone($tenant);
        $month = $this->month($request->get('month'), $timezone);
        $end = $month->addMonth();

        $monthly = UsageMonthly::query()->whereDate('month', $month->format('Y-m-d'))->first();
        $events = UsageEvent::query()
            ->where('occurred_at', '>=', $month->utc())
            ->where('occurred_at', '<', $end->utc())
            ->toBase()
            ->selectRaw('kind, engine_key, sum(calls) as calls, sum(cost_usd) as cost_usd')
            ->groupBy('kind', 'engine_key')->orderBy('kind')->orderBy('engine_key')
            ->get()
            ->map(fn (object $row): array => [
                'kind' => (string) $row->kind,
                'engine' => $row->engine_key,
                'calls' => (int) $row->calls,
                'cost_usd' => round((float) $row->cost_usd, 4),
            ])->values()->all();

        $plan = $tenant->plan;
        $calls = array_sum(array_column($events, 'calls'));
        $cost = round(array_sum(array_column($events, 'cost_usd')), 4);
        $quota = $monthly->quota_calls ?? $plan?->monthly_call_quota;
        $budget = $monthly->budget_usd ?? $plan?->monthly_budget_usd;

        return new ToolResult(
            __('mcp.summaries.get_usage', ['month' => $month->format('Y-m'), 'calls' => $calls, 'quota' => $quota ?? '–']),
            [
                'month' => $month->format('Y-m'),
                'plan' => $plan === null ? null : ['name' => $plan->name, 'monthly_call_quota' => $plan->monthly_call_quota, 'monthly_budget_usd' => $plan->monthly_budget_usd === null ? null : (float) $plan->monthly_budget_usd],
                'calls' => $calls,
                'cost_usd' => $cost,
                'quota_calls' => $quota === null ? null : (int) $quota,
                'budget_usd' => $budget === null ? null : (float) $budget,
                'status' => $monthly->status ?? 'ok',
                'by_kind_and_engine' => $events,
            ],
        );
    }

    private function month(mixed $value, string $timezone): CarbonImmutable
    {
        if ($value === null || $value === '') {
            return CarbonImmutable::now($timezone)->startOfMonth();
        }
        try {
            $month = is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $value, $timezone) : null;
        } catch (Throwable) {
            $month = null;
        }
        if ($month === null || $month->format('Y-m') !== $value) {
            throw new ToolFailure(__('mcp.errors.month'));
        }

        return $month->startOfMonth();
    }
}
