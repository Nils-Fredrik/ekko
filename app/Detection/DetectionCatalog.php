<?php

namespace App\Detection;

use App\Models\Brand;
use App\Models\BrandAlias;
use App\Models\BrandDomain;
use App\Models\Competitor;
use App\Models\CompetitorAlias;
use App\Models\CompetitorDomain;

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
        $brandIds = Brand::query()->where('status', 'active')->pluck('id')->all();
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
