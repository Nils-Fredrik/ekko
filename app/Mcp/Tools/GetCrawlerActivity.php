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

#[Name('get_crawler_activity')]
#[Description('AI-robottreff mot merkevarens egne domener (forespørsler per robot, fra serverlogger) og AI-trafikk (økter, engasjerte økter og konverteringer per AI-tjeneste, fra GA4) i en periode. Tomt når loggimport eller GA4 ikke er satt opp.')]
#[IsReadOnly]
class GetCrawlerActivity extends EkkoTool
{
    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->tenantArgument($schema),
            ...$this->brandArgument($schema),
            ...$this->periodArguments($schema),
        ];
    }

    protected function run(Request $request, McpContext $context, Team $tenant): ToolResult
    {
        $brand = $this->brand($request);
        $filter = $this->filter($request, $tenant, $brand);
        $metrics = app(MetricsService::class);
        $bots = $metrics->botHits($filter);
        $traffic = $metrics->aiTraffic($filter);

        return new ToolResult(
            __('mcp.summaries.get_crawler_activity', [
                'brand' => $brand->name,
                'hits' => array_sum(array_column($bots, 'hits')),
                'sessions' => array_sum(array_column($traffic, 'sessions')),
            ]),
            [
                'brand' => ['id' => $brand->id, 'name' => $brand->name],
                'period' => ['from' => $filter->from, 'to' => $filter->to],
                'bot_hits' => $bots,
                'ai_traffic' => $traffic,
            ],
        );
    }
}
