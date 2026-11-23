<?php

namespace App\Brands;

use App\Enums\ActionStatus;
use App\MeasurementPlan\MeasurementPlans;
use App\MeasurementPlan\PlanDraft;
use App\MeasurementPlan\PromptPopulation;
use App\Models\Action;
use App\Models\Brand;
use App\Models\Prompt;
use App\Models\RunBatch;
use App\Models\UsageEvent;
use App\Models\UserBrandPreference;
use App\Onboarding\CostEstimator;
use App\Prompts\EntityQueue;
use App\Scheduling\NextMeasurement;
use App\Usage\MonthlyUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

final readonly class BrandHealth
{
    public function __construct(private MeasurementPlans $plans, private CostEstimator $estimator, private EntityQueue $queue, private MonthlyUsage $usage, private NextMeasurement $next) {}

    /** @return array<string, mixed> */
    public function for(Brand $brand): array
    {
        $team = $brand->tenant;
        $markets = $brand->markets()->where('is_active', true)->orderBy('id')->get()->keyBy('id');
        $populations = PromptPopulation::forMarkets($markets->modelKeys());
        $input = [];
        foreach ($this->plans->preview($brand, new PlanDraft(null, [])) as $id => $settings) {
            if ($settings['is_active']) {
                $input[] = $settings + ['id' => $id, 'prompts' => $populations[$id] ?? new PromptPopulation];
            }
        }
        $estimate = $this->estimator->estimate($team, $input);
        $latest = RunBatch::whereIn('market_id', $markets->modelKeys())->where('status', 'completed')
            ->selectRaw('market_id, max(updated_at) as finished')->groupBy('market_id')->pluck('finished', 'market_id');
        $today = CarbonImmutable::today($team->timezone);
        $planned = RunBatch::whereIn('market_id', $markets->modelKeys())->where('dispatch_date', $today->toDateString())->pluck('market_id')->flip();
        $prompts = Prompt::whereIn('market_id', $markets->modelKeys())->where('is_active', true)->get(['id', 'market_id', 'priority'])->groupBy('market_id');
        $measurements = [];
        $engines = [];
        foreach ($estimate['markets'] as $estimateMarket) {
            $id = $estimateMarket['id'];
            $market = $markets[$id];
            foreach ($estimateMarket['rules'] as $rule) {
                if ($rule['frequency'] !== 'off') {
                    $engines[$rule['engine_key']] = true;
                }
            }
            $start = $planned->has($id) ? $today->addDay() : $today;
            $measurements[] = [
                'id' => $id, 'label' => $market->location_label ?: strtoupper($market->country),
                'last_completed_at' => $latest->has($id) ? CarbonImmutable::parse($latest[$id])->utc()->toIso8601String() : null,
                'next_due_on' => $this->next->on($estimateMarket['rules'], $prompts->get($id, collect()), $start),
            ];
        }

        $plan = route('brands.setup.markets', $brand);
        $integrations = route('brands.setup.integrations', $brand);
        $entities = route('brands.setup.competitors', $brand);
        $problems = [];
        $this->problem($problems, $brand->integrations()->where('status', 'error')->exists(), 'integration', 'error', $integrations);
        $this->problem($problems, in_array('no_price', array_column($estimate['issues'], 'code'), true), 'price', 'error', $plan);
        if (Gate::allows('viewAny', UsageEvent::class)) {
            $this->problem($problems, $this->usage->snapshot($team, CarbonImmutable::now())->status !== 'ok', 'quota', 'warning', route('usage.index'));
        }
        if (count($problems) < 3) {
            $this->problem($problems, $this->queue->pending($brand) !== [], 'entity', 'warning', $entities);
        }
        if (count($problems) < 3) {
            $this->problem($problems, Action::where('brand_id', $brand->id)->where('status', ActionStatus::New)->exists(), 'action', 'warning', route('brands.actions.index', $brand));
        }
        $enough = $markets->isNotEmpty();
        foreach ($markets as $market) {
            $population = $populations[$market->id] ?? new PromptPopulation;
            $enough = $enough && $population->total >= 20 && $population->core >= 5;
        }
        $checklist = [
            $this->step('plan', $engines !== [], $plan),
            $this->step('prompts', $enough, route('brands.prompts.index', $brand)),
            $this->step('entities', $brand->entities_reviewed_at !== null, $entities),
            $this->step('facts', $brand->facts()->where('is_active', true)->count() >= 3, route('brands.facts.index', $brand)),
            $this->step('recipients', $brand->reportSchedules()->whereJsonLength('recipients', '>', 0)->exists(), route('brands.setup.notifications', $brand)),
            $this->step('integration', $brand->integrations()->whereIn('type', ['clickhouse_logs', 'ga4'])->exists(), $integrations, true),
        ];

        return ['markets' => $measurements, 'engines_active' => count($engines), 'answers_per_month' => $estimate['answers'],
            'problems' => $problems, 'checklist' => $checklist,
            'checklist_hidden' => UserBrandPreference::where('brand_id', $brand->id)->where('user_id', auth()->id())->whereNotNull('checklist_hidden_at')->exists()];
    }

    /** @param list<array<string, string>> $problems */
    private function problem(array &$problems, bool $active, string $kind, string $severity, string $url): void
    {
        if ($active) {
            $problems[] = ['kind' => $kind, 'severity' => $severity, 'text' => (string) __('inbox.problems.'.$kind), 'url' => $url];
        }
    }

    /** @return array<string, mixed> */
    private function step(string $key, bool $done, string $url, bool $optional = false): array
    {
        return ['key' => $key, 'done' => $done, 'optional' => $optional, 'text' => __('inbox.steps.'.$key),
            'hint' => __('inbox.hints.'.$key), 'url' => $url];
    }
}
