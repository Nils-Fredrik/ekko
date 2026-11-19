<?php

namespace App\FactCheck;

use App\Models\BrandFact;
use App\Models\Response;

/**
 * The active facts of the tenant's brands, read once per batch (docs/spec.md 11.6). The facts of the brand an
 * answer belongs to go into its extraction request, so the model can judge the claims in the answer against them.
 * Runs in the tenant's context under row-level security.
 */
final class FactCatalog
{
    /** @param  array<int, array{name: string, facts: list<array{id: int, text: string}>}>  $brands  by brand id */
    private function __construct(private readonly array $brands) {}

    public static function load(): self
    {
        $brands = [];
        $facts = BrandFact::query()->where('is_active', true)->with('brand:id,name')->orderBy('id')->get();
        foreach ($facts as $fact) {
            $brands[$fact->brand_id] ??= ['name' => (string) $fact->brand?->name, 'facts' => []];
            $brands[$fact->brand_id]['facts'][] = ['id' => $fact->id, 'text' => $fact->text];
        }

        return new self($brands);
    }

    /**
     * The facts the answer is checked against: those of the brand whose prompt produced it. Empty when the brand
     * has none (nothing about claims is then sent to the model).
     *
     * @return list<array{id: int, text: string}>
     */
    public function factsFor(Response $response): array
    {
        return $this->brands[self::brandId($response)]['facts'] ?? [];
    }

    /** @return list<int> the brands that have at least one active fact */
    public function brandIds(): array
    {
        return array_keys($this->brands);
    }

    /** The name of the brand the answer belongs to, so the model knows whose claims count. */
    public function brandName(Response $response): string
    {
        return $this->brands[self::brandId($response)]['name'] ?? '';
    }

    /** @return list<int> */
    public function factIds(Response $response): array
    {
        return array_column($this->factsFor($response), 'id');
    }

    public static function brandId(Response $response): int
    {
        $response->loadMissing('run.prompt.market');

        return (int) data_get($response, 'run.prompt.market.brand_id', 0);
    }
}
