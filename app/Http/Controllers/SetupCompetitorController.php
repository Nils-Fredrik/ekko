<?php

namespace App\Http\Controllers;

use App\Dashboard\BrandPage;
use App\Dashboard\DashboardLabels;
use App\Metrics\Formulas;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\CompetitorAlias;
use App\Models\CompetitorDomain;
use App\Models\EntityDecision;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Team;
use App\Prompts\EntityQueue;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Setup › Competitors and brands (docs/ux-arbeidsflate.md 5.3, U22): the queue of unknown names, the competitors
 * with their color order, and the names decided as another actor or ignored. The writing routes are U21's
 * (EntityDecisionController and CompetitorSetupController); the page calls them as JSON.
 */
class SetupCompetitorController extends Controller
{
    public const TABS = ['pending', 'competitors', 'other', 'ignored'];

    /** The days visibility is measured over in the competitor table. */
    private const VISIBILITY_DAYS = 28;

    public function __invoke(Request $request, Brand $brand, EntityQueue $queue, MetricsService $metrics): InertiaResponse
    {
        BrandPage::authorizeView($brand);
        $brand->load('markets');
        $market = $this->market($brand, $request);
        $marketIds = $market === null ? [] : [(int) $market->id];
        $tab = $request->query('tab');
        $tab = is_string($tab) && in_array($tab, self::TABS, true) ? $tab : 'pending';

        $pending = array_map(fn ($item): array => $item->toArray(), $queue->pending($brand, $marketIds));
        $decisions = EntityDecision::query()->where('brand_id', $brand->id)
            ->whereIn('decision', [EntityDecision::OTHER, EntityDecision::IGNORED])
            ->with('decider:id,name')->orderByDesc('decided_at')->orderByDesc('id')->get();

        $shares = $decisions->where('decision', EntityDecision::OTHER)->isEmpty() ? [] : $this->shares($brand, $metrics, $marketIds);

        return Inertia::render('Brands/Setup/Competitors', [
            'brand' => BrandPage::payload($brand),
            'markets' => BrandPage::markets($brand),
            'market' => $market === null ? null : [
                'id' => $market->id,
                'label' => $market->location_label ?: strtoupper($market->country),
            ],
            'tab' => $tab,
            'engineNames' => $this->engineNames($brand),
            'queue' => $pending,
            'queueTotal' => $this->unknownCount($brand, $queue, $marketIds),
            'competitors' => $this->competitors($brand, $metrics, $marketIds),
            'others' => $this->decided($decisions, EntityDecision::OTHER, $shares),
            'ignored' => $this->decided($decisions, EntityDecision::IGNORED, []),
            'targets' => array_map(fn (array $target): array => [
                'type' => $target['type'], 'id' => $target['id'], 'name' => $target['name'],
            ], EntityQueue::targets($brand)),
            'minAnswers' => (int) config('ekko.entities.min_answers'),
            'undoHours' => (int) config('ekko.entities.undo_hours'),
            'canManage' => Gate::allows('create', Competitor::class) && Gate::allows('create', EntityDecision::class),
            'canManageBrand' => Gate::allows('update', $brand),
        ]);
    }

    private function market(Brand $brand, Request $request): ?Market
    {
        if (! $request->filled('market')) {
            return null;
        }
        $id = filter_var($request->query('market'), FILTER_VALIDATE_INT);
        abort_if($id === false, 404);
        $market = $brand->markets->firstWhere('id', $id);
        abort_if($market === null, 404);

        return $market;
    }

    /**
     * Every competitor that is not archived, in color order, with aliases, domains and visibility over the last
     * 28 days against the 28 days before.
     *
     * @param  list<int>  $marketIds
     * @return list<array<string, mixed>>
     */
    private function competitors(Brand $brand, MetricsService $metrics, array $marketIds): array
    {
        $zone = $this->zone($brand);
        $today = CarbonImmutable::now($zone)->startOfDay();
        $current = $this->visibility($metrics, new MetricsFilter($brand->id, $today->subDays(self::VISIBILITY_DAYS - 1), $today, $marketIds));
        $before = $this->visibility($metrics, new MetricsFilter(
            $brand->id, $today->subDays(2 * self::VISIBILITY_DAYS - 1), $today->subDays(self::VISIBILITY_DAYS), $marketIds,
        ));
        $colors = Competitor::colorIndexes($brand);

        return Competitor::query()->where('brand_id', $brand->id)->with(['aliases', 'domains'])->inColorOrder()->get()
            ->map(function (Competitor $competitor) use ($colors, $current, $before): array {
                $now = $current[$competitor->id] ?? null;
                $then = $before[$competitor->id] ?? null;

                return [
                    'id' => $competitor->id,
                    'name' => $competitor->name,
                    'is_active' => $competitor->is_active,
                    'position' => $competitor->position,
                    'color_index' => $colors[$competitor->id] ?? null,
                    'aliases' => $competitor->aliases->sortBy('id')->map(fn (CompetitorAlias $alias): array => [
                        'id' => $alias->id, 'alias' => $alias->alias, 'match_mode' => $alias->match_mode,
                    ])->values()->all(),
                    'domains' => $competitor->domains->sortBy('id')->map(fn (CompetitorDomain $domain): array => [
                        'id' => $domain->id, 'domain' => $domain->domain,
                    ])->values()->all(),
                    'visibility' => $now,
                    'change' => $now === null || $then === null ? null : $now - $then,
                ];
            })->all();
    }

    /**
     * @return array<int, float|null> active competitor id => share of answers naming it
     */
    private function visibility(MetricsService $metrics, MetricsFilter $filter): array
    {
        $shares = [];
        foreach ($metrics->voiceParts($filter) as $part) {
            if ($part['id'] !== 'brand') {
                $shares[(int) $part['id']] = Formulas::ratio($part['mentions'], $part['n']);
            }
        }

        return $shares;
    }

    /**
     * The share of the answers of the last queue window that name each decided name.
     *
     * @param  list<int>  $marketIds
     * @return array<string, array{share: float|null, answers: int}>
     */
    private function shares(Brand $brand, MetricsService $metrics, array $marketIds): array
    {
        $scope = $this->marketScope($brand, $marketIds);
        if ($scope === []) {
            return [];
        }
        [$from, $to] = EntityQueue::window($brand, (int) config('ekko.entities.queue_days'));
        $shares = [];
        foreach ($metrics->unknownBrands(new MetricsFilter($brand->id, $from, $to, $scope)) as $row) {
            $shares[$row['key']] = ['share' => $row['metric']->value, 'answers' => $row['answers']];
        }

        return $shares;
    }

    /**
     * Every unknown name of the window without a decision, also the ones seen in fewer than the minimum of answers,
     * for the line under the queue («Showing x of n»).
     *
     * @param  list<int>  $marketIds
     */
    private function unknownCount(Brand $brand, EntityQueue $queue, array $marketIds): int
    {
        $scope = $this->marketScope($brand, $marketIds);
        if ($scope === []) {
            return 0;
        }
        [$from, $to] = EntityQueue::window($brand, (int) config('ekko.entities.queue_days'));

        return count($queue->unknown($brand, new MetricsFilter($brand->id, $from, $to, $scope)));
    }

    /**
     * The brand's markets, narrowed to the chosen ones.
     *
     * @param  list<int>  $marketIds
     * @return list<int>
     */
    private function marketScope(Brand $brand, array $marketIds): array
    {
        $own = $brand->markets->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        return $marketIds === [] ? $own : array_values(array_intersect($own, $marketIds));
    }

    /**
     * @param  Collection<int, EntityDecision>  $decisions
     * @param  array<string, array{share: float|null, answers: int}>  $shares
     * @return list<array<string, mixed>>
     */
    private function decided(Collection $decisions, string $kind, array $shares): array
    {
        return $decisions->where('decision', $kind)->map(fn (EntityDecision $decision): array => [
            'id' => $decision->id,
            'key' => $decision->name_key,
            'name' => $decision->display_name,
            'variants' => $decision->variants,
            'decided_by' => $decision->decider?->name,
            'decided_at' => $decision->decided_at->toIso8601String(),
            'undoable' => $decision->undoable(),
            'share' => $shares[$decision->name_key]['share'] ?? null,
            'answers' => $shares[$decision->name_key]['answers'] ?? null,
        ])->values()->all();
    }

    /**
     * The names of the engines the brand's markets use, for the sources of the excerpts.
     *
     * @return array<string, string>
     */
    private function engineNames(Brand $brand): array
    {
        $keys = MarketEngine::query()->whereIn('market_id', $brand->markets->pluck('id'))->distinct()->pluck('engine_key')
            ->map(fn (mixed $key): string => (string) $key)->all();

        return DashboardLabels::engines($keys);
    }

    private function zone(Brand $brand): string
    {
        $zone = Team::query()->whereKey($brand->getAttribute('tenant_id'))->value('timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }
}
