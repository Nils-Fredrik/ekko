<?php

namespace App\Reports;

use App\ActionEngine\ActionTemplates;
use App\Enums\ActionStatus;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Action;
use App\Models\Team;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/**
 * The action section of a report (docs/spec.md 11.3): the 3–5 highest ranked open actions, and actions
 * completed in the period with the change in visibility since they were completed. The same payload is
 * drawn in the PDF and on the web page, so U13 can reuse it.
 */
final class ReportActions
{
    public function __construct(private readonly MetricsService $metrics) {}

    /**
     * The section stored with the report. A report written before that column existed has none, and is not filled
     * from today's actions.
     *
     * @param  array<string, mixed>|null  $stored
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
    public function published(?array $stored): array
    {
        if (is_array($stored) && is_array($stored['open'] ?? null) && is_array($stored['completed'] ?? null) && is_string($stored['title'] ?? null)) {
            return [
                'title' => $stored['title'],
                'open_title' => is_string($stored['open_title'] ?? null) ? $stored['open_title'] : '',
                'done_title' => is_string($stored['done_title'] ?? null) ? $stored['done_title'] : '',
                'empty_open' => is_string($stored['empty_open'] ?? null) ? $stored['empty_open'] : '',
                'empty_done' => is_string($stored['empty_done'] ?? null) ? $stored['empty_done'] : '',
                'change_label' => is_string($stored['change_label'] ?? null) ? $stored['change_label'] : '',
                'open' => array_values($stored['open']),
                'completed' => array_values($stored['completed']),
            ];
        }

        return $this->empty();
    }

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
        [$start, $end] = $this->bounds($from, $to);
        $completed = Action::query()
            ->where('brand_id', $brandId)
            ->where('status', ActionStatus::Done)
            ->where('completed_at', '>=', $start)
            ->where('completed_at', '<', $end)
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
    private function empty(): array
    {
        return [
            'title' => __('reports.actions'),
            'open_title' => __('reports.actions_open'),
            'done_title' => __('reports.actions_done'),
            'empty_open' => __('reports.actions_empty_open'),
            'empty_done' => __('reports.actions_empty_done'),
            'change_label' => __('reports.actions_change'),
            'open' => [],
            'completed' => [],
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
        $timezone = $this->timezone();
        $done = CarbonImmutable::parse($action->completed_at)->setTimezone($timezone)->startOfDay();
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone)->startOfDay();
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

    /**
     * The UTC instants [start, end) covering the tenant-local days from $from through $to.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(string $from, string $to): array
    {
        $timezone = $this->timezone();

        return [
            CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone)->startOfDay()->utc(),
            CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone)->addDay()->startOfDay()->utc(),
        ];
    }

    private function timezone(): DateTimeZone
    {
        $name = Team::query()->whereKey(current_tenant_id())->value('timezone');

        try {
            return new DateTimeZone(is_string($name) && $name !== '' ? $name : 'UTC');
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
