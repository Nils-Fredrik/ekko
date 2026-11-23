<?php

namespace App\Http\Controllers\Api;

use App\Api\Query;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AlertResource;
use App\Http\Resources\Api\BrandResource;
use App\Http\Resources\Api\CompetitorResource;
use App\Http\Resources\Api\PromptResource;
use App\Http\Resources\Api\ResponseResource;
use App\Http\Resources\Api\UsageResource;
use App\Metrics\MetricsService;
use App\Models\Alert;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\UsageMonthly;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReadController extends Controller
{
    public function brands(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Brand::class);

        return BrandResource::collection(Brand::query()->unarchived()->orderBy('id')->paginate(Query::pageSize($request))->withQueryString());
    }

    public function prompts(Request $request, Brand $brand): AnonymousResourceCollection
    {
        Gate::authorize('view', $brand);
        Gate::authorize('viewAny', Prompt::class);

        return PromptResource::collection(Prompt::query()->when(! $request->boolean('include_archived'), fn ($q) => $q->whereNull('archived_at'))->whereHas('market', fn ($q) => $q->where('brand_id', $brand->id))
            ->orderBy('id')->paginate(Query::pageSize($request))->withQueryString());
    }

    public function competitors(Request $request, Brand $brand): AnonymousResourceCollection
    {
        Gate::authorize('view', $brand);
        Gate::authorize('viewAny', Competitor::class);

        return CompetitorResource::collection(Competitor::query()->where('brand_id', $brand->id)
            ->orderBy('id')->paginate(Query::pageSize($request))->withQueryString());
    }

    public function alerts(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Alert::class);

        return AlertResource::collection(Alert::query()->orderByDesc('triggered_at')->orderByDesc('id')
            ->paginate(Query::pageSize($request))->withQueryString());
    }

    public function usage(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', UsageMonthly::class);
        $data = $request->validate(['month' => ['sometimes', 'date_format:Y-m']]);
        $month = ($data['month'] ?? CarbonImmutable::now(Query::timezone())->format('Y-m')).'-01';
        $usage = UsageMonthly::query()->whereDate('month', $month)->first();

        return response()->json(['data' => $usage === null ? null : new UsageResource($usage)]);
    }

    public function metrics(Request $request, Brand $brand, MetricsService $metrics): JsonResponse
    {
        Gate::authorize('view', $brand);
        $size = Query::pageSize($request);
        $data = $request->validate(['group_by' => ['sometimes', Rule::in(['day', 'week', 'month', 'market', 'engine', 'prompt', 'topic', 'intent', 'funnel_stage'])]]);
        $filter = Query::filter($request, $brand);
        $group = $data['group_by'] ?? 'day';
        if (in_array($group, MetricsService::GRAINS, true)) {
            $rows = array_map(fn ($row) => ['period' => $row['period'], 'metrics' => $row['summary']->toArray()], $metrics->series($filter, $group));
        } else {
            $rows = [];
            foreach ($metrics->breakdown($filter, $group) as $value => $summary) {
                $rows[] = ['group' => (string) $value, 'metrics' => $summary->toArray()];
            }
        }
        $page = (int) $request->input('page', 1);
        $series = new LengthAwarePaginator(array_slice($rows, ($page - 1) * $size, $size), count($rows), $size, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return response()->json([
            'data' => ['summary' => $metrics->summary($filter)->toArray(), 'series' => $series],
            'meta' => ['from' => $filter->from, 'to' => $filter->to, 'timezone' => Query::timezone(), 'group_by' => $group],
        ]);
    }

    public function sources(Request $request, Brand $brand, MetricsService $metrics): JsonResponse
    {
        Gate::authorize('view', $brand);
        $size = Query::pageSize($request);
        $filter = Query::filter($request, $brand);

        return response()->json($metrics->sourcesPage($filter, $size)->withQueryString());
    }

    public function responses(Request $request, Brand $brand): AnonymousResourceCollection
    {
        Gate::authorize('view', $brand);
        Gate::authorize('viewAny', Response::class);
        $size = Query::pageSize($request);
        $filter = Query::filter($request, $brand);
        $data = $request->validate(['prompt' => ['sometimes', 'integer', 'min:1']]);
        if (isset($data['prompt'])) {
            Prompt::query()->whereHas('market', fn ($q) => $q->where('brand_id', $brand->id))->findOrFail($data['prompt']);
        }
        $from = CarbonImmutable::parse($filter->from, Query::timezone())->utc();
        $until = CarbonImmutable::parse($filter->to, Query::timezone())->addDay()->utc();
        $query = Response::query()->with(['run', 'mentions', 'citations'])
            ->whereHas('run.prompt.market', fn ($q) => $q->where('brand_id', $brand->id))
            ->where('collected_at', '>=', $from)->where('collected_at', '<', $until)
            ->when(isset($data['prompt']), fn ($q) => $q->whereHas('run', fn ($run) => $run->where('prompt_id', $data['prompt'])))
            ->when($filter->engineKeys !== [], fn ($q) => $q->whereIn('engine_key', $filter->engineKeys))
            ->when($filter->marketIds !== [], fn ($q) => $q->whereHas('run.prompt', fn ($prompt) => $prompt->whereIn('market_id', $filter->marketIds)));

        return ResponseResource::collection($query->orderByDesc('collected_at')->orderByDesc('id')->paginate($size)->withQueryString());
    }
}
