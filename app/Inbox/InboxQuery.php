<?php

namespace App\Inbox;

use App\ActionEngine\Sources\FactCorrectionSource;
use App\Alerts\AlertType;
use App\Dashboard\DashboardLabels;
use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Models\Action;
use App\Models\Brand;
use App\Models\Claim;
use App\Models\Market;
use App\Models\Mention;
use App\Models\Team;
use App\Prompts\EntityQueue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

final readonly class InboxQuery
{
    private const TYPES = ['alert', 'entity', 'action', 'claim', 'integration'];

    public function __construct(private InboxAlerts $alerts, private EntityQueue $entities) {}

    /** @return list<InboxItem> */
    public function items(Team $team, ?Brand $brand = null, ?Market $market = null, ?string $type = null, bool $handled = false): array
    {
        abort_unless($team->id === current_tenant_id() && (! $brand || $brand->tenant_id === $team->id)
            && (! $market || ($market->tenant_id === $team->id && (! $brand || $market->brand_id === $brand->id))), 404);
        $brand ??= $market?->brand;
        $type = match ($type) {
            'all', '' => null, 'alerts' => 'alert', 'entities' => 'entity', 'actions' => 'action', 'claims' => 'claim', 'integrations' => 'integration', default => $type,
        };
        $rows = ($type === null || $type === 'alert') ? $this->alerts->items($team, $brand, $market, $handled) : [];
        if ($type !== 'alert') {
            $brands = $brand ? [$brand] : Brand::query()->orderBy('id')->get();
            foreach ($brands as $owner) {
                foreach ($type === null ? ['entity', 'action', 'claim', 'integration'] : [$type] as $kind) {
                    array_push($rows, ...match ($kind) {
                        'entity' => $this->entityRows($owner, $market, $handled),
                        'action' => $this->actionRows($owner, $market, $handled),
                        'claim' => $this->claimRows($owner, $market, $handled),
                        'integration' => $this->integrationRows($owner, $handled),
                        default => [],
                    });
                }
            }
        }
        usort($rows, fn (InboxItem $a, InboxItem $b) => CarbonImmutable::parse($b->at) <=> CarbonImmutable::parse($a->at)
            ?: [$a->type, $a->key] <=> [$b->type, $b->key]);

        return $rows;
    }

    /** @return array<string, int> */
    public function counts(Team $team, ?Brand $brand = null, ?Market $market = null): array
    {
        $counts = array_fill_keys(self::TYPES, 0);
        foreach ($this->items($team, $brand, $market) as $item) {
            $counts[$item->type]++;
        }

        return ['all' => array_sum($counts)] + $counts;
    }

    /** @return list<InboxItem> */
    private function entityRows(Brand $brand, ?Market $market, bool $handled): array
    {
        $rows = [];
        $type = AlertType::NewCompetitor;
        $rule = $brand->alertRules()->where('type', $type->value)->first();
        $threshold = (float) ($rule->threshold ?? $type->defaultThreshold());
        $markets = $brand->markets()->where('is_active', true)->when($market, fn ($q) => $q->whereKey($market->id))->get();
        $url = route(Route::has('brands.setup.competitors') ? 'brands.setup.competitors' : 'brands.competitors.index', $brand);
        foreach ($markets as $scope) {
            if ($rule && ! $rule->is_active) {
                break;
            }
            foreach ($this->entities->pending($brand, [$scope->id], 7) as $entity) {
                if ($entity->share === null || $entity->share * 100 <= $threshold) {
                    continue;
                }
                $suggested = $entity->suggestion['decision'] ?? 'competitor';
                $buttons = array_values(array_unique([$suggested, 'competitor', 'other', 'ignored']));
                $rows[] = new InboxItem('entity', $brand->id.':'.$scope->id.':'.$entity->key, $brand, $scope, $entity->lastSeen,
                    'medium-beige', __('inbox.entity', ['name' => $entity->name]), __('inbox.share', ['percent' => round($entity->share * 100)]),
                    $buttons, $url, false, [$entity->key], ['suggestion' => $entity->suggestion]);
            }
        }
        if ($handled) {
            foreach ($brand->entityDecisions()->get() as $decision) {
                if ($market && ! Mention::whereHas('response.run.prompt', fn ($q) => $q->where('market_id', $market->id))
                    ->where(fn ($q) => $q->whereIn('id', $decision->mention_ids)->orWhereIn('surface_name', [$decision->display_name, ...$decision->variants]))->exists()) {
                    continue;
                }
                $rows[] = new InboxItem('entity', 'decision:'.$decision->id, $brand, $market, $decision->decided_at, 'medium-beige',
                    __('inbox.entity', ['name' => $decision->display_name]), __('inbox.resolved'), ['open'], $url, true, [$decision->id]);
            }
        }

        return $rows;
    }

    /** @return list<InboxItem> */
    private function actionRows(Brand $brand, ?Market $market, bool $handled): array
    {
        $rows = [];
        $actions = Action::where('brand_id', $brand->id)->with('market')
            ->when(! $handled, fn ($q) => $q->where('status', ActionStatus::New))
            ->when($market, fn ($q) => $q->where(fn ($q) => $q->whereNull('market_id')->orWhere('market_id', $market->id)))->get();
        foreach ($actions as $action) {
            $done = $action->status !== ActionStatus::New;
            $rows[] = new InboxItem('action', (string) $action->id, $brand, $action->market, $action->created_at ?? now(), 'marker-action',
                $action->title, $action->description, $done ? ['open'] : ['plan', 'decline'],
                route('brands.actions.show', [$brand, $action]), $done, [$action->id]);
        }

        return $rows;
    }

    /** @return list<InboxItem> */
    private function claimRows(Brand $brand, ?Market $market, bool $handled): array
    {
        $claims = Claim::contradicted()->where('brand_id', $brand->id)->whereHas('fact')
            ->whereHas('response', FactCorrectionSource::inboxResponses(CarbonImmutable::now($brand->tenant->timezone)))
            ->when($market, fn ($q) => $q->whereHas('response.run.prompt', fn ($p) => $p->where('market_id', $market->id)))
            ->with(['fact', 'response:id,run_id,collected_at', 'response.run:id,prompt_id,engine_key', 'response.run.prompt:id,market_id', 'response.run.prompt.market'])->get()->sortByDesc('response.collected_at')->groupBy('fact_id');
        $linked = Action::where('brand_id', $brand->id)->where('type', ActionType::FactCorrection)->where('status', '!=', ActionStatus::Expired)
            ->selectRaw("evidence->>'fact_id' AS fact_id")->toBase()->pluck('fact_id')->filter()->flip();
        $rows = [];
        foreach ($claims as $id => $group) {
            $claim = $group->first();
            $done = $linked->has($id) || ! $claim->fact->is_active;
            if ($done && ! $handled) {
                continue;
            }
            $run = $claim->response->run;
            $rows[] = new InboxItem('claim', (string) $claim->id, $brand, $group->pluck('response.run.prompt.market_id')->unique()->count() === 1 ? $run->prompt->market : null,
                $claim->response->collected_at, 'error', __('inbox.claim', ['engine' => DashboardLabels::engine($run->engine_key), 'text' => $claim->text]),
                __('inbox.fact', ['text' => $claim->fact->text]), $done ? ['open'] : ['create_action', 'open'],
                route('brands.facts.index', $brand), $done, $group->pluck('id')->all());
        }

        return $rows;
    }

    /** @return list<InboxItem> */
    private function integrationRows(Brand $brand, bool $handled): array
    {
        $rows = [];
        $integrations = $brand->integrations()->where(fn ($q) => $q->where('status', 'error')
            ->when($handled, fn ($q) => $q->orWhereNotNull('last_error_at')))->get(['id', 'type', 'status', 'updated_at']);
        foreach ($integrations as $integration) {
            $done = $integration->status !== 'error';
            $rows[] = new InboxItem('integration', (string) $integration->id, $brand, null, $integration->updated_at ?? now(), 'error',
                __('inbox.integration', ['name' => __('alerts.integrations.'.$integration->type)]), __($done ? 'inbox.resolved' : 'inbox.sync_failed'),
                ['open_integration'], route(Route::has('brands.setup.integrations') ? 'brands.setup.integrations' : 'brands.settings', $brand), $done, [$integration->id]);
        }

        return $rows;
    }
}
