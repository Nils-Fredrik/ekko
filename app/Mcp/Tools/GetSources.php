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

#[Name('get_sources')]
#[Description('Kildene i svarene for en merkevare i en periode: de mest siterte domenene med kildeandel (andel svar som siterer domenet), og kildegapet: domener som ofte siteres i svar der konkurrenter nevnes og merkevaren ikke gjør det. Kan avgrenses til markeder og motorer, ikke temaer eller prompts.')]
#[IsReadOnly]
class GetSources extends EkkoTool
{
    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->tenantArgument($schema),
            ...$this->brandArgument($schema),
            ...$this->periodArguments($schema),
            ...$this->marketEngineArguments($schema),
            'limit' => $schema->integer()->description('Antall domener i hver liste, 1–100. Standard 25.'),
        ];
    }

    protected function run(Request $request, McpContext $context, Team $tenant): ToolResult
    {
        $brand = $this->brand($request);
        $filter = $this->filter($request, $tenant, $brand);
        $limit = max(1, min(100, (int) ($request->get('limit') ?: 25)));
        $metrics = app(MetricsService::class);
        $share = $metrics->sourceShare($filter, $limit);
        $gap = $metrics->sourceGap($filter, $limit);

        return new ToolResult(
            __('mcp.summaries.get_sources', [
                'brand' => $brand->name,
                'top' => $share[0]['domain'] ?? '–',
                'gap' => $gap[0]['domain'] ?? '–',
            ]),
            [
                'brand' => ['id' => $brand->id, 'name' => $brand->name],
                'period' => ['from' => $filter->from, 'to' => $filter->to],
                'filters' => ['markets' => $filter->marketIds, 'engines' => $filter->engineKeys],
                'source_share' => $share,
                'source_gap' => $gap,
            ],
        );
    }
}
