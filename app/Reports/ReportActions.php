<?php

namespace App\Reports;

use App\ActionEngine\ActionTemplates;
use App\Enums\ActionStatus;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Action;
use Carbon\CarbonImmutable;

/**
 * The action section of a report (docs/spec.md 11.3): the 3–5 highest ranked open actions, and actions
 * completed in the period with the change in visibility since they were completed. The same payload is
 * drawn in the PDF and on the web page, so U13 can reuse it.
 */
final class ReportActions
{
    public function __construct(private readonly MetricsService $metrics) {}

    /**
     * @return array{
     *     title: string,
     *     open_title: string,
     *     done_title: string,
     *     empty_open: string,
     *     empty_done: string,
     *     change_label: string,
     *     open: list<array{title: string, impact: string, evidence: string}>,
     *     completed: list<array{title: string, impact: string, evidence: string, change: string}>
     * }
     */
    public function section(int $brandId, string $from, string $to): array
    {
        $open = Action::query()->where('brand_id', $brandId)->open()->ranked()->limit(5)->get();
        $completed = Action::query()
            ->where('brand_id', $brandId)
            ->where('status', ActionStatus::Done)
            ->whereDate('completed_at', '>=', $from)
            ->whereDate('completed_at', '<=', $to)
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get();

        return [
            'title' => __('reports.actions'),
            'open_title' => __('reports.actions_open'),
            'done_title' => __('reports.actions_done'),
            'empty_open' => __('reports.actions_empty_open'),
            'empty_done' => __('reports.actions_empty_done'),
            'change_label' => __('reports.actions_change'),
            'open' => $open->map(fn (Action $action): array => $this->row($action))->all(),
            'completed' => $completed->map(fn (Action $action): array => [
                ...$this->row($action),
                'change' => $this->change($action, $to),
            ])->all(),
        ];
    }

    /** @return array{title: string, impact: string, evidence: string} */
    private function row(Action $action): array
    {
        return [
            'title' => $action->title,
            'impact' => (string) __('action_items.impact_levels.'.$action->impact_level),
            'evidence' => ActionTemplates::evidenceSentence($action, app()->getLocale()) ?? '',
        ];
    }

    /**
     * Visibility in the days from completion through the period end, minus the same number of days before.
     */
    private function change(Action $action, string $to): string
    {
        if ($action->completed_at === null) {
            return __('reports.empty_value');
        }
        $done = CarbonImmutable::parse($action->completed_at)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        if ($done->greaterThan($end)) {
            return __('reports.empty_value');
        }
        $days = (int) $done->diffInDays($end) + 1;
        $markets = $action->market_id === null ? [] : [$action->market_id];
        $after = $this->metrics->summary(new MetricsFilter($action->brand_id, $done, $end, $markets))->visibility->value;
        $beforeEnd = $done->subDay();
        $before = $this->metrics->summary(new MetricsFilter(
            $action->brand_id,
            $beforeEnd->subDays($days - 1),
            $beforeEnd,
            $markets,
        ))->visibility->value;

        return $this->delta($after, $before);
    }

    private function delta(?float $current, ?float $previous): string
    {
        if ($current === null || $previous === null) {
            return __('reports.empty_value');
        }
        $diff = ($current - $previous) * 100;
        $sign = $diff > 0 ? '+' : '';
        $decimal = app()->getLocale() === 'en' ? '.' : ',';

        return $sign.number_format($diff, 1, $decimal, ' ').' pp';
    }
}
