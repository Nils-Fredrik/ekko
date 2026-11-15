<?php

namespace App\Mcp\Tools;

use App\Mcp\EkkoTool;
use App\Mcp\McpContext;
use App\Mcp\ToolResult;
use App\Models\Brand;
use App\Models\Market;
use App\Models\MarketEngine;
use App\Models\Team;
use App\Models\Topic;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_brands')]
#[Description('Merkevarene i tenanten med markeder (land, språk, sted), motorene per marked (frekvens og gjentakelser), temaene og antall aktive konkurrenter og prompts. Gir id-ene de andre verktøyene bruker.')]
#[IsReadOnly]
class ListBrands extends EkkoTool
{
    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [...$this->tenantArgument($schema), ...$this->cursorArgument($schema)];
    }

    protected function run(Request $request, McpContext $context, Team $tenant): ToolResult
    {
        $brands = Brand::query()
            ->with([
                'markets' => fn ($query) => $query->orderBy('id')->withCount(['prompts' => fn ($q) => $q->where('is_active', true)]),
                'markets.marketEngines' => fn ($query) => $query->orderBy('engine_key'),
                'topics' => fn ($query) => $query->orderBy('name'),
            ])
            ->withCount(['competitors' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('name')->orderBy('id')
            ->get()
            ->map(fn (Brand $brand): array => [
                'id' => $brand->id,
                'name' => $brand->name,
                'primary_domain' => $brand->primary_domain,
                'status' => $brand->status,
                'active_competitors' => (int) $brand->getAttribute('competitors_count'),
                'topics' => $brand->topics->map(fn (Topic $topic): array => ['id' => $topic->id, 'name' => $topic->name])->values()->all(),
                'markets' => $brand->markets->map(fn (Market $market): array => [
                    'id' => $market->id,
                    'country' => $market->country,
                    'language' => $market->language,
                    'location' => $market->location_label,
                    'is_active' => (bool) $market->is_active,
                    'active_prompts' => (int) $market->getAttribute('prompts_count'),
                    'engines' => $market->marketEngines->map(fn (MarketEngine $engine): array => [
                        'engine' => $engine->engine_key,
                        'frequency' => $engine->frequency,
                        'prompt_scope' => $engine->prompt_scope,
                        'repetitions' => (int) $engine->repetitions,
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all();

        $page = $this->page($brands, $request);

        return new ToolResult(__('mcp.summaries.list_brands', ['count' => $page['total']]), [
            'brands' => $page['items'],
            'total' => $page['total'],
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
