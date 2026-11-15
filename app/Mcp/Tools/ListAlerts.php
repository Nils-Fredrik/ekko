<?php

namespace App\Mcp\Tools;

use App\Mcp\EkkoTool;
use App\Mcp\McpContext;
use App\Mcp\ToolResult;
use App\Models\Alert;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_alerts')]
#[Description('Varslene i tenanten: alle åpne (ikke kvittert) og de kvitterte fra de siste dagene, nyeste først, med type (synlighetsfall, konkurrent tar over, tapt sitat, ny konkurrent, modellbytte, kvote, integrasjonsfeil), merkevare og innhold.')]
#[IsReadOnly]
class ListAlerts extends EkkoTool
{
    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->tenantArgument($schema),
            'days' => $schema->integer()->description('Hvor mange dager bakover kvitterte varsler tas med, 1–90. Standard 14.'),
            'brand' => $schema->integer()->description('Bare varsler for denne merkevaren.'),
            ...$this->cursorArgument($schema),
        ];
    }

    protected function run(Request $request, McpContext $context, Team $tenant): ToolResult
    {
        $days = max(1, min(90, (int) ($request->get('days') ?: 14)));
        $since = CarbonImmutable::now()->subDays($days);
        $brand = $request->get('brand');

        $alerts = Alert::query()
            ->with('alertRule.brand:id,name')
            ->where(fn ($query) => $query->whereNull('acknowledged_by')->orWhere('triggered_at', '>=', $since))
            ->when(is_numeric($brand), fn ($query) => $query->whereHas('alertRule', fn ($rule) => $rule->where('brand_id', (int) $brand)))
            ->orderByDesc('triggered_at')->orderByDesc('id')
            ->get()
            ->map(fn (Alert $alert): array => [
                'id' => $alert->id,
                'type' => $alert->alertRule?->type,
                'brand' => $alert->alertRule?->brand === null ? null : ['id' => $alert->alertRule->brand->id, 'name' => $alert->alertRule->brand->name],
                'triggered_at' => $this->isoTime($alert->getAttribute('triggered_at')),
                'open' => $alert->acknowledged_by === null,
                'payload' => $alert->payload,
            ])->values()->all();

        $page = $this->page($alerts, $request);
        $open = count(array_filter($alerts, fn (array $alert): bool => $alert['open']));

        return new ToolResult(__('mcp.summaries.list_alerts', ['open' => $open, 'count' => $page['total']]), [
            'alerts' => $page['items'],
            'open' => $open,
            'total' => $page['total'],
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
