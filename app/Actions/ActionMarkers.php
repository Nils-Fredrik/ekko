<?php

namespace App\Actions;

use App\Models\Action;
use Carbon\CarbonImmutable;
use DateTimeZone;

/** The same tenant-local action dates for dashboards and immutable reports (T45). */
final class ActionMarkers
{
    /** @return list<array{key: string, date: string, kind: string, label: string, detail: string, href?: string, action_id?: int}> */
    public function forPeriod(int $brandId, string $from, string $to, DateTimeZone|string $timezone, bool $links = true): array
    {
        $start = CarbonImmutable::parse($from, $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc();
        $actions = Action::query()->where('brand_id', $brandId)
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('started_at', '>=', $start)->where('started_at', '<', $end))
                ->orWhere(fn ($q) => $q->where('completed_at', '>=', $start)->where('completed_at', '<', $end)))
            ->get(['id', 'title', 'started_at', 'completed_at']);
        $markers = [];
        foreach ($actions as $action) {
            foreach (['started_at' => 'action_started', 'completed_at' => 'action_completed'] as $field => $label) {
                $when = $action->{$field};
                if ($when === null || $when->lessThan($start) || ! $when->lessThan($end)) {
                    continue;
                }
                $markers[] = [
                    'key' => 'action:'.$action->id.':'.$field,
                    'date' => $when->copy()->setTimezone($timezone)->toDateString(),
                    'kind' => 'action', 'label' => $action->title,
                    'detail' => __('app.charts.'.$label),
                    ...($links ? ['href' => route('brands.actions.show', [$brandId, $action->id]), 'action_id' => $action->id] : []),
                ];
            }
        }
        usort($markers, fn (array $a, array $b): int => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);

        return $markers;
    }
}
