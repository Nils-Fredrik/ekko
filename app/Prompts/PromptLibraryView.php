<?php

namespace App\Prompts;

use App\Metrics\Metric;
use App\Metrics\MetricsFilter;
use App\Metrics\MetricsService;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use Carbon\CarbonImmutable;

final class PromptLibraryView
{
    public function __construct(private MetricsService $metrics) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payload(Brand $brand, ?Market $market, array $filters): array
    {
        abort_if($market !== null && $market->brand_id !== $brand->id, 404);
        $today = CarbonImmutable::now(Team::query()->findOrFail(current_tenant_id())->timezone ?? 'UTC');
        $period = new MetricsFilter($brand->id, $today->subDays(27), $today, $market === null ? [] : [$market->id]);
        $topicMetrics = $this->metrics->breakdown($period, 'topic');
        $cells = collect($this->metrics->visibilityByPromptEngine($period))->groupBy('prompt_id');
        $markets = $brand->markets()->orderBy('id')->get()->keyBy('id');
        $all = Prompt::query()->whereIn('market_id', $markets->keys())->withMax('responses as last_measured_at', 'collected_at')
            ->withExists('runs')->orderBy('text')->orderBy('id')->get();
        $byTopic = $all->whereNull('archived_at')->groupBy('topic_id');
        $topics = $brand->topics()->orderBy('name')->get()->map(function (Topic $topic) use ($market, $markets, $byTopic, $topicMetrics): array {
            $members = $byTopic->get($topic->id, collect());

            return [
                'id' => $topic->id, 'name' => $topic->name, 'cross_market_key' => $topic->cross_market_key,
                'count' => $market === null ? $members->count() : $members->where('market_id', $market->id)->count(),
                'markets' => $markets->only($members->pluck('market_id')->all())->map->only(['id', 'country', 'language', 'location_label'])->values()->all(),
                'visibility' => ($topicMetrics[$topic->id]->visibility ?? new Metric(null, 0))->toArray(),
            ];
        });
        $prompts = $all->filter(function (Prompt $prompt) use ($market, $filters): bool {
            if ($market !== null && $prompt->market_id !== $market->id) {
                return false;
            }
            if (array_key_exists('topic_id', $filters) && $prompt->topic_id !== ($filters['topic_id'] === null ? null : (int) $filters['topic_id'])) {
                return false;
            }
            foreach (['priority', 'intent', 'funnel_stage'] as $field) {
                if (! empty($filters[$field]) && ! in_array($prompt->getAttribute($field), (array) $filters[$field], true)) {
                    return false;
                }
            }
            if (! str_contains(mb_strtolower($prompt->text), mb_strtolower((string) ($filters['search'] ?? '')))) {
                return false;
            }

            return match ($filters['status'] ?? 'active') {
                'all' => true, 'archived' => $prompt->archived_at !== null,
                'inactive' => $prompt->archived_at === null && ! $prompt->is_active,
                default => $prompt->is_active,
            };
        })->map(fn (Prompt $prompt): array => $prompt->only('id', 'text', 'topic_id', 'market_id', 'intent', 'funnel_stage', 'priority', 'is_active', 'last_measured_at', 'runs_exists') + [
            'archived' => $prompt->archived_at !== null, 'engines' => $cells->get($prompt->id, collect())->pluck('visibility', 'engine_key')->all(),
        ])->values();

        return ['topics' => $topics->all(), 'prompts' => $prompts->all()];
    }
}
