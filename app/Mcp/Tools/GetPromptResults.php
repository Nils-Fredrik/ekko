<?php

namespace App\Mcp\Tools;

use App\Mcp\EkkoTool;
use App\Mcp\McpContext;
use App\Mcp\ToolResult;
use App\Metrics\MetricsService;
use App\Metrics\MetricsSummary;
use App\Models\Prompt;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_prompt_results')]
#[Description('Resultatene per prompt i perioden, sammenlignet med like lang periode rett før: synlighet, anbefalingsrate og gjennomsnittlig posisjon med n, og endringen i synlighet. Sortert etter størst endring først. Bruk get_responses for å lese svarene til et prompt.')]
#[IsReadOnly]
class GetPromptResults extends EkkoTool
{
    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->tenantArgument($schema),
            'include_archived' => $schema->boolean()->description('Ta med arkiverte prompts.'),
            ...$this->brandArgument($schema),
            ...$this->periodArguments($schema),
            ...$this->marketEngineArguments($schema),
            'topics' => $schema->array()->items($schema->integer())->description('Tema-id-er (se list_brands).'),
            ...$this->cursorArgument($schema),
        ];
    }

    protected function run(Request $request, McpContext $context, Team $tenant): ToolResult
    {
        $brand = $this->brand($request);
        [$from, $to] = $this->period($request, $tenant);
        $topics = ['topicIds' => $this->intList($request->get('topics'))];
        $current = $this->filter($request, $tenant, $brand, [$from, $to], $topics);
        $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        $previous = $this->filter($request, $tenant, $brand, [
            CarbonImmutable::parse($from)->subDays((int) $days)->format('Y-m-d'),
            CarbonImmutable::parse($from)->subDay()->format('Y-m-d'),
        ], $topics);

        $metrics = app(MetricsService::class);
        $now = $metrics->breakdown($current, 'prompt');
        $before = $metrics->breakdown($previous, 'prompt');

        $prompts = Prompt::query()->when($request->get('include_archived') !== true, fn ($q) => $q->whereNull('archived_at'))->with('market:id,country,language')
            ->whereIn('id', array_map('intval', array_keys($now + $before)))
            ->get()->keyBy('id');

        $rows = [];
        foreach ($prompts as $id => $prompt) {
            $nowSummary = $now[(string) $id] ?? null;
            $beforeSummary = $before[(string) $id] ?? null;
            $change = $nowSummary?->visibility->value !== null && $beforeSummary?->visibility->value !== null
                ? $nowSummary->visibility->value - $beforeSummary->visibility->value
                : null;
            $rows[] = [
                'prompt_id' => $prompt->id,
                'text' => $prompt->text,
                'market' => $prompt->market === null ? null : trim("{$prompt->market->country} {$prompt->market->language}"),
                'intent' => $prompt->intent,
                'funnel_stage' => $prompt->funnel_stage,
                'is_active' => (bool) $prompt->is_active,
                'archived' => $prompt->archived_at !== null,
                'current' => $this->figures($nowSummary),
                'previous' => $this->figures($beforeSummary),
                'visibility_change' => $change,
            ];
        }
        // Largest measured change first; prompts with figures in only one period (new or stopped) last.
        usort($rows, fn (array $a, array $b): int => [$a['visibility_change'] === null, $b['visibility_change'] === null ? 0 : abs($b['visibility_change']), $a['prompt_id']]
            <=> [$b['visibility_change'] === null, $a['visibility_change'] === null ? 0 : abs($a['visibility_change']), $b['prompt_id']]);
        $page = $this->page($rows, $request);

        return new ToolResult(
            __('mcp.summaries.get_prompt_results', ['brand' => $brand->name, 'count' => $page['total'], 'from' => $from, 'to' => $to]),
            [
                'brand' => ['id' => $brand->id, 'name' => $brand->name],
                'period' => ['from' => $from, 'to' => $to],
                'previous_period' => ['from' => $previous->from, 'to' => $previous->to],
                'prompts' => $page['items'],
                'total' => $page['total'],
                'next_cursor' => $page['next_cursor'],
            ],
        );
    }

    /** @return array<string, mixed>|null */
    private function figures(?MetricsSummary $summary): ?array
    {
        if ($summary === null) {
            return null;
        }

        return [
            'responses' => $summary->responses,
            'visibility' => $summary->visibility->toArray(),
            'recommendation_rate' => $summary->recommendationRate->toArray(),
            'average_position' => $summary->averagePosition->toArray(),
        ];
    }
}
