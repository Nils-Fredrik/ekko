<?php

namespace App\Insights;

use App\ActionEngine\ActionCost;
use App\ActionEngine\Writing\ActionWriterFailed;
use App\ActionEngine\Writing\AnthropicActionWriter;
use App\AnthropicBudget\Account;
use App\AnthropicBudget\Money;
use App\Dashboard\DashboardPeriod;
use App\Metrics\MetricsFilter;
use App\Models\Brand;
use App\Models\Insight;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Tenancy\TenantContext;
use App\Usage\MonthlyUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The insight text on the dashboards (docs/spec.md 14.2, ADR 0038): one short text per brand (or the overview),
 * filter combination, language and tenant-local day. It is written once by Claude Sonnet 5.5 as a template with
 * placeholders and stored; a page view only renders it, so the figures in the text are read from the database at that
 * moment and always equal the figures on the page. Nothing is regenerated on a view.
 *
 * A call counts against the tenant's quota (kind insight, not platform paid). Before the call the estimated cost is
 * reserved on the Anthropic account (ADR 0023), and it is skipped in reduced mode, when the tenant's quota is used
 * up and above ekko.insights.max_per_tenant_per_day. No text is shown, rather than an error, when generation does not
 * happen. Each attempt writes one log line with internal IDs, never text.
 *
 * The caller authorizes access to the brand (or the overview) before calling: stored rows are tenant-scoped.
 */
final readonly class InsightService
{
    /** Reasons that may be tried again later the same day. The others (refused, unknown) are not: they were billed or may have been. */
    private const RETRYABLE = ['reduced', 'quota', 'cap', 'failed'];

    public function __construct(
        private InsightFigureBuilder $builder,
        private InsightPromptBuilder $prompts,
        private InsightTextGuard $guard,
        private ActionCost $cost,
        private MonthlyUsage $usage,
    ) {}

    /**
     * @return array{text: string, label: string, generated_on: string}|null
     */
    public function forBrand(Brand $brand, DashboardPeriod $period, MetricsFilter $filter, MetricsFilter $previous): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->resolve('brand', $brand->id, $period, [
            'market' => $filter->marketIds, 'engine' => $filter->engineKeys, 'topic' => $filter->topicIds, 'intent' => $filter->intents,
        ], fn (): InsightFigures => $this->builder->brand($brand, $period, $filter, $previous));
    }

    /**
     * @param  iterable<Brand>  $brands
     * @return array{text: string, label: string, generated_on: string}|null
     */
    public function forOverview(iterable $brands, DashboardPeriod $period): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->resolve('overview', null, $period, [], fn (): InsightFigures => $this->builder->overview($brands, $period));
    }

    /** Switched on in configuration, and with a key to call the model with. */
    private function enabled(): bool
    {
        return (bool) config('ekko.insights.enabled') && AnthropicActionWriter::configured();
    }

    /**
     * @param  array<string, list<int|string>>  $dimensions
     * @param  callable(): InsightFigures  $figures
     * @return array{text: string, label: string, generated_on: string}|null
     */
    private function resolve(string $scope, ?int $brandId, DashboardPeriod $period, array $dimensions, callable $figures): ?array
    {
        $locale = app()->getLocale();
        $locale = in_array($locale, ['nb', 'sv', 'en'], true) ? $locale : 'en';
        $tenantId = app(TenantContext::class)->require();
        foreach ($dimensions as &$values) {
            $values = array_values(array_unique($values));
            sort($values);
        }
        unset($values);
        $filter = ['days' => $period->days, 'from' => $period->from, 'to' => $period->to] + $dimensions;
        $key = hash('sha256', json_encode(['scope' => $scope, 'brand' => $brandId, 'locale' => $locale, 'filter' => $filter], JSON_THROW_ON_ERROR));

        $data = $figures();
        if (! $data->usable) {
            return null;
        }

        $row = Insight::query()->where('filter_key', $key)->whereDate('local_date', $period->to)->first();
        if ($row === null || ($row->status === Insight::UNAVAILABLE && $this->mayRetry($row))) {
            $lock = Cache::lock("insight:{$tenantId}:{$key}", 120);
            if (! $lock->get()) {
                return null;
            }
            try {
                // Another request may have written it while this one waited for the lock.
                $row = Insight::query()->where('filter_key', $key)->whereDate('local_date', $period->to)->first();
                if ($row === null || ($row->status === Insight::UNAVAILABLE && $this->mayRetry($row))) {
                    $row = $this->generate($row, $scope, $brandId, $period, $key, $filter, $locale, $data);
                }
            } finally {
                $lock->release();
            }
        }

        return $row->status === Insight::WRITTEN ? $this->render($row, $data, $locale) : null;
    }

    private function mayRetry(Insight $row): bool
    {
        return in_array($row->reason, self::RETRYABLE, true)
            && $row->attempted_at->lessThan(CarbonImmutable::now('UTC')->subMinutes((int) config('ekko.insights.retry_after_minutes')));
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function generate(?Insight $row, string $scope, ?int $brandId, DashboardPeriod $period, string $key, array $filter, string $locale, InsightFigures $data): Insight
    {
        $tenantId = app(TenantContext::class)->require();
        $log = ['tenant' => $tenantId, 'brand' => $brandId, 'scope' => $scope];
        $attributes = [
            'brand_id' => $brandId, 'scope' => $scope, 'local_date' => $period->to, 'filter_key' => $key, 'filter' => $filter,
            'locale' => $locale, 'attempted_at' => CarbonImmutable::now('UTC'), 'template' => null, 'directions' => [], 'entities' => [],
            'model' => null, 'usage_event_id' => null,
        ];
        $store = function (string $status, ?string $reason, array $extra = []) use (&$row, $attributes): Insight {
            $values = array_merge($attributes, $extra, ['status' => $status, 'reason' => $reason]);
            if ($row === null) {
                $row = Insight::query()->create($values);
            } else {
                $row->forceFill($values)->save();
            }

            return $row;
        };

        $dayStart = CarbonImmutable::parse($period->to, $period->timezone)->startOfDay()->utc();
        if (Insight::query()->whereNotNull('usage_event_id')->where('attempted_at', '>=', $dayStart)->count() >= (int) config('ekko.insights.max_per_tenant_per_day')) {
            Log::info('Insight call.', $log + ['outcome' => 'not_called', 'reason' => 'cap']);

            return $store(Insight::UNAVAILABLE, 'cap');
        }
        $tenant = Team::query()->findOrFail($tenantId);
        if ($this->usage->threshold($this->usage->snapshot($tenant, CarbonImmutable::now('UTC'))) === 100) {
            Log::info('Insight call.', $log + ['outcome' => 'not_called', 'reason' => 'quota']);

            return $store(Insight::UNAVAILABLE, 'quota');
        }

        $configured = app(Account::class)->configured();
        $operation = 'insight:'.Str::uuid()->toString();
        $log['operation'] = $operation;
        if ($configured && ! Account::independent()->reserve($operation, Money::units((string) config('ekko.anthropic.insight.estimated_cost_usd')), false)) {
            Log::info('Insight call.', $log + ['outcome' => 'not_reserved']);

            return $store(Insight::UNAVAILABLE, 'reduced');
        }

        $request = $this->prompts->build($data, $scope, $locale, $period->days);
        try {
            // Resolved here: without ANTHROPIC_API_KEY the container cannot build the client.
            $result = app(InsightTextWriter::class)->write($request);
        } catch (ActionWriterFailed $failure) {
            // The message is an exception class or a fixed code, never provider text.
            Log::warning('Insight call.', $log + ['outcome' => $failure->known ? 'failed' : 'unknown', 'error' => $failure->getMessage(), 'credit_exhausted' => $failure->creditExhausted]);
            if ($configured && $failure->known) {
                Account::independent()->settle($operation);
            }
            if ($failure->creditExhausted) {
                Account::independent()->markExhausted();
            }

            return $store(Insight::UNAVAILABLE, $failure->known ? 'failed' : 'unknown');
        }

        $cost = $this->cost->of($result->inputTokens, $result->outputTokens, 'insight');
        if ($configured) {
            // Durable before the tenant's booking: a rollback there cannot release a known provider cost.
            Account::independent()->settleKnown($operation, $cost);
        }
        $event = UsageEvent::query()->create([
            'kind' => 'insight',
            'engine_key' => null,
            'occurred_at' => CarbonImmutable::now('UTC'),
            'calls' => 1,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'search_calls' => 0,
            'cost_usd' => $cost,
            'billed_to' => 'platform',
            'anthropic_operation' => $operation,
        ]);

        $template = $result->text === null ? null : $this->guard->parse($result->text, array_keys($data->figures), $data->names, $this->prompts->maxLength($scope));
        Log::info('Insight call.', $log + [
            'outcome' => $template === null ? 'refused' : 'written',
            'usage_event' => $event->id,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cost_usd' => $cost,
        ]);
        $extra = ['usage_event_id' => $event->id, 'model' => (string) config('ekko.anthropic.insight.model')];

        return $template === null
            ? $store(Insight::UNAVAILABLE, 'refused', $extra)
            : $store(Insight::WRITTEN, null, $extra + ['template' => $template, 'directions' => $data->directions(), 'entities' => $data->entities()]);
    }

    /**
     * Fills the placeholders from the figures read now. The text is hidden when a figure is gone or a change has
     * turned the other way since the text was written.
     *
     * @return array{text: string, label: string, generated_on: string}|null
     */
    private function render(Insight $row, InsightFigures $data, string $locale): ?array
    {
        $template = (string) $row->template;
        preg_match_all('/\{([a-z0-9_]+)\}/', $template, $found);
        foreach (array_unique($found[1]) as $placeholder) {
            $figure = $data->figures[$placeholder] ?? null;
            // The text names the entity behind a movement placeholder; if the ranking has moved on, it no longer fits.
            if ($figure === null
                || (isset($row->directions[$placeholder]) && $row->directions[$placeholder] !== $figure->direction)
                || (isset($row->entities[$placeholder]) && $row->entities[$placeholder] !== $figure->entity)) {
                return null;
            }
        }

        $text = preg_replace_callback('/\{([a-z0-9_]+)\}/', fn (array $m): string => InsightFormatter::format($data->figures[$m[1]], $locale), $template);

        return [
            'text' => (string) $text,
            'label' => (string) trans('insights.label', [], $locale),
            'generated_on' => $row->local_date->toDateString(),
        ];
    }
}
