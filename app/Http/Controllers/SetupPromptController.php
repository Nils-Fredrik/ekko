<?php

namespace App\Http\Controllers;

use App\Dashboard\BrandPage;
use App\Dashboard\DashboardLabels;
use App\Models\Activity;
use App\Models\Brand;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Team;
use App\Models\User;
use App\Prompts\AnswerSegments;
use App\Prompts\PromptLibrary;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Setup › Prompts and topics (docs/ux-arbeidsflate.md 5.2, U20). The writing routes live in U19.
 */
class SetupPromptController extends Controller
{
    public function __invoke(Request $request, Brand $brand, PromptLibrary $library): InertiaResponse
    {
        BrandPage::authorizeView($brand);
        $market = $this->market($brand, $request);
        $topic = $this->selectedTopic($brand, $request);
        $filters = $this->filters($request, $topic);
        $payload = $library->payload($brand, $market, $filters);
        $team = Team::query()->findOrFail(current_tenant_id());
        $zone = $team->timezone ?? 'UTC';
        $marketIds = $market === null ? $brand->markets()->pluck('id') : collect([$market->id]);
        $unscoped = Prompt::query()->whereIn('market_id', $marketIds)->whereNull('topic_id')->whereNull('archived_at')->count();
        $engines = $this->engines($marketIds->all());
        $limit = $team->plan?->max_prompts_per_market;
        $active = $market === null ? null : Prompt::query()->where('market_id', $market->id)->where('is_active', true)->count();
        $detail = $request->filled('open') ? $this->detail($brand, $request, $zone, $market) : null;

        return Inertia::render('Brands/Setup/Prompts', [
            'brand' => BrandPage::payload($brand),
            'markets' => BrandPage::markets($brand->load('markets')),
            'market' => $market === null ? null : [
                'id' => $market->id,
                'label' => $market->location_label ?: strtoupper($market->country),
            ],
            'library' => $payload,
            'unscoped' => $unscoped,
            'engines' => $engines,
            'quota' => ['active' => $active, 'limit' => $limit],
            'canManage' => Gate::allows('create', Prompt::class),
            'options' => [
                'intents' => $this->options(Prompt::INTENTS, DashboardLabels::intent(...)),
                'funnels' => $this->options(Prompt::FUNNELS, DashboardLabels::funnel(...)),
                'priorities' => $this->options(Prompt::PRIORITIES, DashboardLabels::priority(...)),
            ],
            'filters' => [
                'topic' => $topic,
                'status' => $filters['status'],
                'search' => $filters['search'],
                'intent' => array_values(array_filter((array) $request->query('intent', []))),
                'funnel' => array_values(array_filter((array) $request->query('funnel', []))),
                'priority' => array_values(array_filter((array) $request->query('priority', []))),
            ],
            'open' => $detail === null ? null : (int) $request->query('open'),
            'detail' => $detail,
            'importResult' => session('prompt_import'),
            'personalData' => (bool) session('personal_data'),
        ]);
    }

    private function market(Brand $brand, Request $request): ?Market
    {
        if (! $request->filled('market')) {
            return null;
        }
        $id = filter_var($request->query('market'), FILTER_VALIDATE_INT);
        abort_if($id === false, 404);
        $market = $brand->markets()->whereKey($id)->first();
        abort_if($market === null, 404);

        return $market;
    }

    /**
     * A topic created or chosen as the merge target is selected on the redirect back.
     */
    private function selectedTopic(Brand $brand, Request $request): mixed
    {
        $created = session('topic_id');
        if (is_numeric($created) && $brand->topics()->whereKey((int) $created)->exists()) {
            return (string) (int) $created;
        }

        $topic = $request->query('topic');
        if ($topic === 'none') {
            return 'none';
        }

        return is_numeric($topic) ? (string) (int) $topic : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request, mixed $topic): array
    {
        $status = $this->text($request, 'status', 'active');
        $filters = [
            'status' => in_array($status, ['active', 'inactive', 'archived', 'all'], true) ? $status : 'active',
            'search' => $this->text($request, 'q'),
            'intent' => array_values(array_filter((array) $request->query('intent', []))),
            'funnel_stage' => array_values(array_filter((array) $request->query('funnel', []))),
            'priority' => array_values(array_filter((array) $request->query('priority', []))),
        ];
        if ($topic === 'none') {
            $filters['topic_id'] = null;
        } elseif (is_numeric($topic)) {
            $filters['topic_id'] = (int) $topic;
        }

        return $filters;
    }

    private function text(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * @param  list<int>  $marketIds
     * @return list<array{key: string, name: string, scope: string}>
     */
    private function engines(array $marketIds): array
    {
        $rows = MarketEngine::query()->whereIn('market_id', $marketIds)->where('frequency', '!=', 'off')->orderBy('engine_key')->get();
        $names = DashboardLabels::engines($rows->pluck('engine_key')->unique()->values()->all());
        $engines = [];
        foreach ($rows->groupBy('engine_key') as $key => $rules) {
            $engines[] = [
                'key' => (string) $key,
                'name' => $names[(string) $key] ?? DashboardLabels::engine((string) $key),
                'scope' => $rules->contains('prompt_scope', 'all') ? 'all' : 'core',
            ];
        }

        return $engines;
    }

    /**
     * @param  list<string>  $values
     * @return list<array{value: string, label: string}>
     */
    private function options(array $values, callable $label): array
    {
        return array_map(fn (string $value): array => ['value' => $value, 'label' => $label($value)], $values);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function detail(Brand $brand, Request $request, string $zone, ?Market $market): ?array
    {
        if (! $request->filled('open')) {
            return null;
        }
        $id = filter_var($request->query('open'), FILTER_VALIDATE_INT);
        abort_if($id === false, 404);
        $prompt = Prompt::query()->whereKey($id)->whereIn('market_id', $brand->markets()->pluck('id'))->first();
        if ($prompt === null) {
            // Gone (just deleted) closes the panel. A prompt on another of this tenant's brands is not ours.
            abort_if(Prompt::query()->whereKey($id)->exists(), 404);

            return null;
        }
        if ($market !== null && $prompt->market_id !== $market->id) {
            return null;
        }
        $today = CarbonImmutable::now($zone)->startOfDay();
        $from = $today->subDays(6)->utc();
        $responses = Response::query()
            ->whereHas('run', fn ($run) => $run->where('prompt_id', $prompt->id))
            ->where('collected_at', '>=', $from)
            ->with(['mentions' => fn ($mentions) => $mentions->orderBy('first_char_offset')->orderBy('id')])
            ->orderByDesc('collected_at')
            ->get();
        $scopes = MarketEngine::query()->where('market_id', $prompt->market_id)->where('frequency', '!=', 'off')->get()->groupBy('engine_key');
        $names = DashboardLabels::engines($scopes->keys()->map(fn (mixed $key): string => (string) $key)->all());
        $days = [];
        for ($offset = 6; $offset >= 0; $offset--) {
            $days[] = $today->subDays($offset)->toDateString();
        }
        $results = [];
        foreach ($scopes as $key => $rules) {
            $coreOnly = ! $rules->contains('prompt_scope', 'all') && $prompt->priority !== 'core';
            $mine = $responses->where('engine_key', $key);
            $dots = [];
            $places = [];
            foreach ($days as $day) {
                $onDay = $mine->filter(function (Response $response) use ($day, $zone): bool {
                    $collected = $response->collected_at;

                    return $collected instanceof CarbonInterface && $collected->copy()->timezone($zone)->toDateString() === $day;
                });
                $mentioned = $onDay->contains(fn (Response $response): bool => $response->mentions->contains(
                    fn ($mention): bool => $mention->entity_type === 'brand' && (int) $mention->entity_id === (int) $brand->id,
                ));
                $dots[] = $mentioned;
                foreach ($onDay as $response) {
                    $place = $response->mentions->first(
                        fn ($mention): bool => $mention->entity_type === 'brand' && (int) $mention->entity_id === (int) $brand->id,
                    )?->list_position;
                    if ($place !== null) {
                        $places[] = (int) $place;
                    }
                }
            }
            $results[] = [
                'key' => (string) $key,
                'name' => $names[(string) $key] ?? DashboardLabels::engine((string) $key),
                'measured' => ! $coreOnly && $mine->isNotEmpty(),
                'reason' => $coreOnly ? 'core' : null,
                'dots' => $dots,
                'days' => count(array_filter($dots)),
                'place' => $places === [] ? null : (int) round(array_sum($places) / count($places)),
            ];
        }
        $latest = Response::query()
            ->whereHas('run', fn ($run) => $run->where('prompt_id', $prompt->id))
            ->with(['mentions' => fn ($mentions) => $mentions->orderBy('first_char_offset')->orderBy('id')])
            ->orderByDesc('collected_at')
            ->orderByDesc('id')
            ->first();
        $history = Activity::query()->where('subject_type', $prompt->getMorphClass())->where('subject_id', $prompt->id)
            ->with('causer')->latest()->limit(30)->get()->map(function (Activity $activity): array {
                $changes = $activity->attribute_changes ?? collect();
                $raw = $changes->get('attributes');
                $attributes = $raw instanceof Collection ? $raw->all() : (is_array($raw) ? $raw : []);
                $who = $activity->causer;

                return [
                    'at' => $activity->created_at?->toIso8601String(),
                    'who' => $who instanceof User ? $who->name : null,
                    'fields' => collect($attributes)->map(fn (mixed $value, mixed $field): array => [
                        'field' => (string) $field,
                        'value' => is_scalar($value) || $value === null ? (string) $value : '',
                    ])->values()->all(),
                ];
            })->all();

        return [
            'prompt' => $prompt->only('id', 'text', 'topic_id', 'market_id', 'intent', 'funnel_stage', 'priority', 'is_active'),
            'results' => $results,
            'answer' => $latest === null ? null : [
                'at' => $latest->collected_at instanceof CarbonInterface ? $latest->collected_at->toIso8601String() : null,
                'engine' => DashboardLabels::engine((string) $latest->engine_key),
                'segments' => AnswerSegments::from((string) $latest->answer_text, $latest->mentions),
            ],
            'history' => $history,
            'runs' => (bool) $prompt->runs()->exists(),
        ];
    }
}
