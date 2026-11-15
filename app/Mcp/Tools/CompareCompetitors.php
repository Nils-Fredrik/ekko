<?php

namespace App\Mcp\Tools;

use App\Mcp\EkkoTool;
use App\Mcp\McpContext;
use App\Mcp\ToolResult;
use App\Metrics\MetricsService;
use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('compare_competitors')]
#[Description('Merkevaren mot de aktive konkurrentene i en periode: share of voice, synlighet og antall svar som nevner hver konkurrent, og merkevarens egen posisjon og anbefalingsrate. Posisjon og anbefalingsrate er foreløpig bare regnet ut for merkevaren, ikke for konkurrentene.')]
#[IsReadOnly]
class CompareCompetitors extends EkkoTool
{
    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->tenantArgument($schema),
            ...$this->brandArgument($schema),
            ...$this->periodArguments($schema),
            ...$this->marketEngineArguments($schema),
        ];
    }

    protected function run(Request $request, McpContext $context, Team $tenant): ToolResult
    {
        $brand = $this->brand($request);
        $filter = $this->filter($request, $tenant, $brand);
        $metrics = app(MetricsService::class);
        $summary = $metrics->summary($filter);
        $competitors = array_map(fn (array $row): array => [
            'id' => $row['competitor_id'],
            'name' => $row['name'],
            'mentions' => $row['mentions'],
            'visibility' => $row['visibility']->toArray(),
            'share_of_voice' => $row['share_of_voice']->toArray(),
        ], $metrics->competitorComparison($filter));

        $leader = $competitors[0] ?? null;

        return new ToolResult(
            __('mcp.summaries.compare_competitors', [
                'brand' => $brand->name,
                'sov' => $this->percent($summary->shareOfVoice->value),
                'count' => count($competitors),
                'leader' => $leader['name'] ?? '–',
            ]),
            [
                'brand' => [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'responses' => $summary->responses,
                    'visibility' => $summary->visibility->toArray(),
                    'share_of_voice' => $summary->shareOfVoice->toArray(),
                    'recommendation_rate' => $summary->recommendationRate->toArray(),
                    'average_position' => $summary->averagePosition->toArray(),
                    'first_place_rate' => $summary->firstPlaceRate->toArray(),
                ],
                'period' => ['from' => $filter->from, 'to' => $filter->to],
                'filters' => ['markets' => $filter->marketIds, 'engines' => $filter->engineKeys],
                'competitors' => $competitors,
            ],
        );
    }
}
