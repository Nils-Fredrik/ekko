<?php

namespace App\Http\Controllers;

use App\Dashboard\AnalysisFilters;
use App\Dashboard\BrandPage;
use App\Dashboard\CompetitorPage;
use App\Dashboard\DashboardPeriod;
use App\Dashboard\GraphCache;
use App\Detection\EntityName;
use App\Metrics\MetricsFilter;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Team;
use App\Prompts\EntityQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Competitors: their rank over time, visibility, change, rank and sentiment against the brand (U07,
 * docs/ui-forbedring.md 7.4), their aliases and domains, and unknown brands that can be added in one click
 * (docs/spec.md 14.2, T27).
 */
class BrandCompetitorController extends Controller
{
    public function index(Request $request, Brand $brand, CompetitorPage $page, EntityQueue $queue): Response
    {
        BrandPage::authorizeView($brand);
        $brand->load('markets');
        $query = AnalysisFilters::query($brand, $request->query(), 'brands.competitors.index');
        $period = DashboardPeriod::fromInput($query);
        $selection = AnalysisFilters::selection($query);
        $grain = CompetitorPage::grain($period);
        $filter = $period->filter($brand->id, ...$selection);
        $previous = $period->filter($brand->id, ...$selection, previous: true);

        $colorIndexes = Competitor::colorIndexes($brand);
        $competitors = Competitor::query()
            ->where('brand_id', $brand->id)
            ->with(['aliases', 'domains'])
            ->inColorOrder()
            ->get()
            ->map(fn (Competitor $competitor): array => [
                'id' => $competitor->id,
                'name' => $competitor->name,
                'is_active' => $competitor->is_active,
                // The fixed color place among the active competitors (docs/ux-arbeidsflate.md 6.3).
                'color_index' => $colorIndexes[$competitor->id] ?? null,
                'aliases' => $competitor->aliases->pluck('alias')->all(),
                'domains' => $competitor->domains->pluck('domain')->all(),
            ])->all();

        return Inertia::render('Brands/Competitors', [
            'brand' => BrandPage::payload($brand),
            'period' => $period->toArray(),
            'analysisFilters' => AnalysisFilters::payload($brand, 'brands.competitors.index', $query),
            'filters' => $query,
            'markets' => BrandPage::markets($brand),
            'competitors' => $competitors,
            'suggestions' => $this->suggestions($brand, $filter, $queue),
            'canManage' => Gate::allows('create', Competitor::class),
            'chartData' => Inertia::defer(fn (): array => app(GraphCache::class)->remember(
                'brand.competitors.u07.'.$grain.'.'.$period->comparison, $filter, $period->timezone,
                fn (): array => $page->chartData($filter, $previous, $grain),
                competitorIds: $brand->competitors->modelKeys(),
            )),
        ]);
    }

    public function store(Request $request, Brand $brand): RedirectResponse
    {
        Gate::authorize('create', Competitor::class);

        $name = trim((string) $request->validate([
            'name' => ['required', 'string', 'max:200'],
        ])['name']);

        DB::transaction(function () use ($brand, $name): void {
            // Conversion and onboarding take this same lock before changing pitch state.
            $tenant = Team::query()->whereKey(current_tenant_id())->lock('for no key update')->firstOrFail();
            // One lock per brand, so two clicks cannot both pass the duplicate check.
            Brand::query()->whereKey($brand->id)->lockForUpdate()->first();

            if ($name === '' || EntityName::key($name) === '' || isset(EntityQueue::knownKeys($brand)[EntityName::key($name)])) {
                throw ValidationException::withMessages(['name' => __('app.library.duplicate')]);
            }

            if ($tenant->kind === 'pitch' && $brand->competitors()->count() >= 3) {
                throw ValidationException::withMessages(['name' => __('pitch.limits')]);
            }

            $competitor = $brand->competitors()->create([
                'name' => $name,
                'is_active' => true,
            ]);
            $competitor->aliases()->create([
                'alias' => $name,
                'match_mode' => 'case_insensitive',
            ]);
        });

        return back();
    }

    /**
     * Unknown brands of the page's filters in at least two answers, without known or decided names, most answers
     * first (EntityQueue, docs/ux-arbeidsflate.md 6.3).
     *
     * @return list<array{name: string, responses: int}>
     */
    private function suggestions(Brand $brand, MetricsFilter $filter, EntityQueue $queue): array
    {
        $minimum = (int) config('ekko.entities.min_answers');

        return array_values(array_map(
            fn (array $row): array => ['name' => $row['name'], 'responses' => $row['answers']],
            array_filter($queue->unknown($brand, $filter), fn (array $row): bool => $row['answers'] >= $minimum),
        ));
    }

    public function destroy(Brand $brand, string $competitor): RedirectResponse
    {
        $record = $brand->competitors()->lockForUpdate()->findOrFail($competitor);
        Gate::authorize('delete', $record);
        $record->delete();

        return back(fallback: route('brands.competitors.index', $brand));
    }
}
