<?php

namespace App\Detection;

use App\Models\Brand;
use App\Models\BrandAlias;
use App\Models\BrandDomain;
use App\Models\Competitor;
use App\Models\CompetitorAlias;
use App\Models\CompetitorDomain;
use App\Models\Response;

/**
 * Everything deterministic detection needs from the tenant in the context: all aliases of its active
 * brands and active competitors (docs/spec.md 8.1) and the domains for citation ownership. Load it once
 * and reuse it for a batch of responses.
 */
final class DetectionCatalog
{
    /**
     * @param  list<AliasPattern>  $aliases
     */
    public function __construct(
        public readonly array $aliases,
        public readonly DomainOwnership $domains,
    ) {}

    /** Loads the catalog for the tenant in the context; throws without one, like every tenant-owned model. */
    public static function load(): self
    {
        return self::forBrands(Brand::query()->where('status', 'active')->pluck('id')->all());
    }

    /**
     * A run admitted before a brand was archived still uses its aliases and domains. Responses in a
     * tenant batch usually share the same catalog, even when the batch spans many markets.
     *
     * @param  iterable<Response>  $responses
     * @return array<int, self> keyed by response ID
     */
    public static function forResponses(iterable $responses): array
    {
        $brands = Brand::query()->whereIn('status', ['active', 'archived'])->get(['id', 'status', 'archived_at']);
        $active = $brands->where('status', 'active')->modelKeys();
        $archived = $brands->where('status', 'archived');
        $catalogs = [];
        $byResponse = [];
        foreach ($responses as $response) {
            $start = $response->run->created_at;
            $historical = $archived->filter(fn (Brand $brand) => $brand->archived_at?->gte($start) === true)->modelKeys();
            $key = implode(',', $historical);
            $catalogs[$key] ??= self::forBrands([...$active, ...$historical]);
            $byResponse[$response->id] = $catalogs[$key];
        }

        return $byResponse;
    }

    /** @param list<int> $brandIds */
    private static function forBrands(array $brandIds): self
    {
        $competitorIds = Competitor::query()->where('is_active', true)->whereIn('brand_id', $brandIds)->pluck('id')->all();

        $aliases = [];
        foreach (BrandAlias::query()->whereIn('brand_id', $brandIds)->orderBy('id')->get() as $row) {
            $aliases[] = new AliasPattern('brand', $row->brand_id, $row->alias, $row->match_mode);
        }
        foreach (CompetitorAlias::query()->whereIn('competitor_id', $competitorIds)->orderBy('id')->get() as $row) {
            $aliases[] = new AliasPattern('competitor', $row->competitor_id, $row->alias, $row->match_mode);
        }

        $brandDomains = BrandDomain::query()->whereIn('brand_id', $brandIds)->get()
            ->map(fn (BrandDomain $row): array => ['domain' => $row->domain, 'include_subdomains' => (bool) $row->include_subdomains]);
        $competitorDomains = CompetitorDomain::query()->whereIn('competitor_id', $competitorIds)->get()
            ->map(fn (CompetitorDomain $row): array => ['domain' => $row->domain, 'competitor_id' => (int) $row->competitor_id]);

        return new self($aliases, new DomainOwnership($brandDomains, $competitorDomains));
    }
}
