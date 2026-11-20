<?php

namespace App\Dashboard;

use App\Enums\ActionStatus;
use App\Models\Action;
use App\Models\Alert;
use App\Models\Competitor;
use App\Models\ModelChange;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

/**
 * «Hva har skjedd» on the overview (docs/ui-forbedring.md 7.1, U10): alerts, model changes, completed actions
 * and new competitors of the current tenant in one timeline, newest first. Each source is read only when the
 * role may list it, and every query runs in the tenant scope of the request.
 */
final class OverviewTimeline
{
    public const LIMIT = 10;

    /**
     * @return list<array{key: string, kind: string, at: string, date_only: bool, brand: string|null, title: string, detail: string|null, href: string|null}>
     */
    public function forPeriod(DashboardPeriod $period): array
    {
        // The database session is UTC: compare against the UTC instant of the local start of the period.
        $since = CarbonImmutable::parse($period->from, $period->timezone)->startOfDay()->utc();
        $items = [...$this->alerts($since), ...$this->modelChanges($period), ...$this->actions($since), ...$this->competitors($since)];

        // Newest first. Items on the same instant keep a stable order by key.
        usort($items, fn (array $a, array $b): int => [$b['sort'], $a['key']] <=> [$a['sort'], $b['key']]);

        return array_map(function (array $item): array {
            unset($item['sort']);

            return $item;
        }, array_slice($items, 0, self::LIMIT));
    }

    /** @return list<array<string, mixed>> */
    private function alerts(CarbonInterface $since): array
    {
        if (! Gate::allows('viewAny', Alert::class)) {
            return [];
        }

        return Alert::query()->with('alertRule.brand')
            ->where('triggered_at', '>=', $since)
            ->latest('triggered_at')->latest('id')
            ->limit(self::LIMIT)->get()
            ->map(fn (Alert $alert): array => [
                'key' => 'alert-'.$alert->id,
                'kind' => 'alert',
                'sort' => $alert->triggered_at->getTimestamp(),
                'at' => $alert->triggered_at->toIso8601String(),
                'date_only' => false,
                'brand' => $alert->alertRule->brand->name,
                'title' => DashboardLabels::alertType($alert->alertRule->type),
                'detail' => null,
                'href' => route('alerts.index'),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function modelChanges(DashboardPeriod $period): array
    {
        if (! Gate::allows('viewAny', ModelChange::class)) {
            return [];
        }

        $changes = ModelChange::query()
            ->whereBetween('changed_on', [$period->from, $period->to])
            ->orderByDesc('changed_on')->orderBy('engine_key')
            ->limit(self::LIMIT)->get();
        $labels = DashboardLabels::engines($changes->pluck('engine_key')->unique()->values()->all());

        return $changes->map(function (ModelChange $change) use ($labels, $period): array {
            // A model change is a day, not an instant: it sorts at the end of that day in the tenant's time zone.
            $day = CarbonImmutable::parse($change->changed_on->toDateString(), $period->timezone);

            return [
                'key' => 'model-'.$change->id,
                'kind' => 'model_change',
                'sort' => $day->endOfDay()->getTimestamp(),
                'at' => $day->toDateString(),
                'date_only' => true,
                'brand' => null,
                'title' => $labels[$change->engine_key] ?? DashboardLabels::engine($change->engine_key),
                'detail' => $change->from_model.' → '.$change->to_model,
                'href' => null,
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function actions(CarbonInterface $since): array
    {
        if (! Gate::allows('viewAny', Action::class)) {
            return [];
        }

        return Action::query()->with('brand')
            ->where('status', ActionStatus::Done->value)
            ->where('completed_at', '>=', $since)
            ->latest('completed_at')->latest('id')
            ->limit(self::LIMIT)->get()
            ->map(fn (Action $action): array => [
                'key' => 'action-'.$action->id,
                'kind' => 'action_done',
                'sort' => $action->completed_at?->getTimestamp() ?? 0,
                'at' => $action->completed_at?->toIso8601String() ?? '',
                'date_only' => false,
                'brand' => $action->brand->name,
                'title' => $action->title,
                'detail' => null,
                'href' => route('brands.actions.show', [$action->brand_id, $action->id]),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function competitors(CarbonInterface $since): array
    {
        if (! Gate::allows('viewAny', Competitor::class)) {
            return [];
        }

        return Competitor::query()->with('brand')
            ->where('is_active', true)
            ->where('created_at', '>=', $since)
            ->latest('created_at')->latest('id')
            ->limit(self::LIMIT)->get()
            ->map(fn (Competitor $competitor): array => [
                'key' => 'competitor-'.$competitor->id,
                'kind' => 'competitor',
                'sort' => $competitor->created_at?->getTimestamp() ?? 0,
                'at' => $competitor->created_at?->toIso8601String() ?? '',
                'date_only' => false,
                'brand' => $competitor->brand->name,
                'title' => $competitor->name,
                'detail' => null,
                'href' => route('brands.competitors.index', $competitor->brand_id),
            ])->all();
    }
}
