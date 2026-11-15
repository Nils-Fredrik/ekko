<?php

namespace App\Mcp\Tools;

use App\Mcp\EkkoTool;
use App\Mcp\McpContext;
use App\Mcp\ToolFailure;
use App\Mcp\ToolResult;
use App\Metrics\MetricsService;
use App\Metrics\MetricsSummary;
use App\Models\Market;
use App\Models\ModelChange;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_visibility')]
#[Description('Metrikkene for en merkevare i en periode: synlighet, anbefalingsrate, share of voice, gjennomsnittlig posisjon, førsteplass-rate, sitatrate og sentiment, hver med n. Kan avgrenses til markeder, motorer, temaer, intensjoner og trakttrinn, og deles opp per dag, uke, måned, marked, motor, tema, intensjon, trakttrinn eller prompt. Gir også stabilitet og modellbytter i perioden.')]
#[IsReadOnly]
class GetVisibility extends EkkoTool
{
    private const GROUPS = ['none', 'day', 'week', 'month', 'market', 'engine', 'topic', 'intent', 'funnel_stage', 'prompt'];

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->tenantArgument($schema),
            ...$this->brandArgument($schema),
            ...$this->periodArguments($schema),
            ...$this->marketEngineArguments($schema),
            'topics' => $schema->array()->items($schema->integer())->description('Tema-id-er (se list_brands).'),
            'intents' => $schema->array()->items($schema->string()->enum(['category', 'comparison', 'brand', 'problem', 'local']))->description('Intensjoner.'),
            'funnel_stages' => $schema->array()->items($schema->string()->enum(['awareness', 'consideration', 'decision']))->description('Trakttrinn.'),
            'group_by' => $schema->string()->enum(self::GROUPS)->description('Oppdeling. Standard: none (bare totalen).'),
            ...$this->cursorArgument($schema),
        ];
    }

    protected function run(Request $request, McpContext $context, Team $tenant): ToolResult
    {
        $groupBy = (string) ($request->get('group_by') ?: 'none');
        if (! in_array($groupBy, self::GROUPS, true)) {
            throw new ToolFailure(__('mcp.errors.group_by'));
        }
        $brand = $this->brand($request);
        $filter = $this->filter($request, $tenant, $brand, extra: [
            'topicIds' => $this->intList($request->get('topics')),
            'intents' => $this->stringList($request->get('intents')),
            'funnelStages' => $this->stringList($request->get('funnel_stages')),
        ]);
        $metrics = app(MetricsService::class);
        $summary = $metrics->summary($filter);

        $groups = match ($groupBy) {
            'none' => [],
            'day', 'week', 'month' => array_map(fn (array $row): array => ['period' => $row['period'], ...$row['summary']->toArray()], $metrics->series($filter, $groupBy)),
            default => $this->labelled($groupBy, $metrics->breakdown($filter, $groupBy)),
        };
        $page = $this->page($groups, $request, 50);
        $stability = $metrics->stability($filter);

        return new ToolResult(
            __('mcp.summaries.get_visibility', [
                'brand' => $brand->name,
                'from' => $filter->from,
                'to' => $filter->to,
                'visibility' => $this->percent($summary->visibility->value),
                'n' => $summary->visibility->n,
            ]),
            [
                'brand' => ['id' => $brand->id, 'name' => $brand->name],
                'period' => ['from' => $filter->from, 'to' => $filter->to],
                'filters' => [
                    'markets' => $filter->marketIds, 'engines' => $filter->engineKeys, 'topics' => $filter->topicIds,
                    'intents' => $filter->intents, 'funnel_stages' => $filter->funnelStages,
                ],
                'summary' => $summary->toArray(),
                'stability' => ['mean' => $stability['mean']->toArray(), 'distribution' => $stability['distribution']],
                'group_by' => $groupBy,
                'groups' => $page['items'],
                'next_cursor' => $page['next_cursor'],
                'model_changes' => $metrics->modelChanges($filter->from, $filter->to, $filter->engineKeys)
                    ->map(fn (ModelChange $change): array => [
                        'engine' => $change->engine_key,
                        'date' => substr((string) $this->isoTime($change->getAttribute('changed_on')), 0, 10) ?: null,
                        'from_model' => $change->from_model,
                        'to_model' => $change->to_model,
                    ])->values()->all(),
            ],
        );
    }

    /**
     * Breakdown rows with a readable label for the dimension value.
     *
     * @param  array<string, MetricsSummary>  $breakdown
     * @return list<array<string, mixed>>
     */
    private function labelled(string $dimension, array $breakdown): array
    {
        $ids = array_map('intval', array_filter(array_keys($breakdown), 'is_numeric'));
        $labels = match ($dimension) {
            'market' => Market::query()->whereIn('id', $ids)->get()->mapWithKeys(fn (Market $m) => [$m->id => trim("{$m->country} {$m->language} {$m->location_label}")])->all(),
            'topic' => Topic::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'prompt' => Prompt::query()->whereIn('id', $ids)->get()->mapWithKeys(fn (Prompt $p) => [$p->id => Str::limit((string) $p->text, 160)])->all(),
            default => [],
        };

        $rows = [];
        foreach ($breakdown as $value => $summary) {
            $rows[] = ['value' => (string) $value, 'label' => $labels[(int) $value] ?? ($value === '' ? null : (string) $value), ...$summary->toArray()];
        }

        return $rows;
    }
}
