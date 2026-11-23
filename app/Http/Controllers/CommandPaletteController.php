<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Prompt;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Search for the command palette (docs/ui-forbedring.md 7.9). Brands, prompts and competitors
 * come from the tenant in the request context, so another tenant's rows never appear.
 * Measures (tiltak) are not a model yet; that group arrives with T43.
 */
class CommandPaletteController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = Str::limit(trim($request->string('q')->toString()), 80, '');

        if ($query === '' || app(TenantContext::class)->id() === null) {
            return response()->json($this->empty());
        }

        $like = '%'.addcslashes($query, '\\%_').'%';

        return response()->json([
            'brands' => $this->brands($like),
            'prompts' => $this->prompts($like),
            'competitors' => $this->competitors($like),
        ]);
    }

    /**
     * @return array{brands: list<array<string, mixed>>, prompts: list<array<string, mixed>>, competitors: list<array<string, mixed>>}
     */
    private function empty(): array
    {
        return ['brands' => [], 'prompts' => [], 'competitors' => []];
    }

    /**
     * @return list<array{id: int, label: string, detail: string, href: string}>
     */
    private function brands(string $like): array
    {
        return Brand::query()->unarchived()
            ->where('name', 'ilike', $like)
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn (Brand $brand): array => [
                'id' => $brand->id,
                'label' => $brand->name,
                'detail' => (string) $brand->primary_domain,
                'href' => route('brands.show', $brand),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, detail: string, href: string}>
     */
    private function prompts(string $like): array
    {
        return Prompt::query()
            ->whereRelation('market.brand', 'status', '!=', 'archived')
            ->where('text', 'ilike', $like)
            ->with('market.brand')
            ->orderBy('text')
            ->limit(8)
            ->get()
            ->map(function (Prompt $prompt): ?array {
                $brand = $prompt->market?->brand;
                if (! $brand instanceof Brand) {
                    return null;
                }

                return [
                    'id' => $prompt->id,
                    'label' => $prompt->text,
                    'detail' => $brand->name,
                    'href' => route('brands.prompts.show', ['brand' => $brand, 'prompt' => $prompt]),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, label: string, detail: string, href: string}>
     */
    private function competitors(string $like): array
    {
        return Competitor::query()
            ->whereRelation('brand', 'status', '!=', 'archived')
            ->where('name', 'ilike', $like)
            ->with('brand')
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(function (Competitor $competitor): ?array {
                $brand = $competitor->brand;
                if (! $brand instanceof Brand) {
                    return null;
                }

                return [
                    'id' => $competitor->id,
                    'label' => $competitor->name,
                    'detail' => $brand->name,
                    'href' => route('brands.competitors.index', $brand),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
