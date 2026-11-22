<?php

namespace App\Http\Controllers;

use App\Dashboard\BrandPage;
use App\Models\Brand;
use App\Models\BrandAlias;
use App\Models\BrandDomain;
use App\Models\Citation;
use App\Models\Team;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setup › Identity (docs/ux-arbeidsflate.md 5.5, U24).
 * Name, aliases and domains use the identity routes from U23. Secrets are not on this page.
 */
class SetupIdentityController extends Controller
{
    public function __invoke(Brand $brand): Response
    {
        BrandPage::authorizeView($brand);
        $brand->load(['aliases', 'domains']);
        $tenant = Team::query()->findOrFail((int) app(TenantContext::class)->require());
        $citations = $this->citations($brand);

        return Inertia::render('Brands/Setup/Identity', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'primary_domain' => $brand->primary_domain,
                'status' => $brand->status,
            ],
            'tenantName' => $tenant->name,
            'aliases' => $brand->aliases->map(fn (BrandAlias $alias): array => [
                'id' => $alias->id,
                'alias' => $alias->alias,
                'match_mode' => $alias->match_mode,
            ])->values(),
            'domains' => $brand->domains->map(fn (BrandDomain $domain): array => [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'include_subdomains' => (bool) $domain->include_subdomains,
                'citations' => (int) ($citations[$domain->domain] ?? 0),
            ])->values(),
            'canManage' => Gate::allows('update', $brand),
        ]);
    }

    /** @return array<string, int> */
    private function citations(Brand $brand): array
    {
        $counts = [];
        $rows = Citation::query()
            ->join('responses', 'responses.id', '=', 'citations.response_id')
            ->join('runs', 'runs.id', '=', 'responses.run_id')
            ->join('prompts', 'prompts.id', '=', 'runs.prompt_id')
            ->join('markets', 'markets.id', '=', 'prompts.market_id')
            ->where('markets.brand_id', $brand->id)
            ->where('citations.created_at', '>=', now()->subDays(30))
            ->selectRaw('citations.domain as domain, count(*) as citations')
            ->groupBy('citations.domain')
            ->get();
        foreach ($rows as $row) {
            $counts[(string) $row->getAttribute('domain')] = (int) $row->getAttribute('citations');
        }

        return $counts;
    }
}
