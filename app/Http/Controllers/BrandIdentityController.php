<?php

namespace App\Http\Controllers;

use App\Brands\AliasMatchTest;
use App\Brands\IdentityLibrary;
use App\Detection\AliasPattern;
use App\Detection\UrlNormalizer;
use App\Integrations\BrandIntegrations;
use App\Models\Brand;
use App\Models\Prompt;
use App\Rules\SafeRegexAlias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class BrandIdentityController extends Controller
{
    private const MODES = [AliasPattern::EXACT_WORD, AliasPattern::CASE_INSENSITIVE, AliasPattern::REGEX];

    public function update(Request $request, Brand $brand, IdentityLibrary $identity): RedirectResponse
    {
        Gate::authorize('update', $brand);
        $identity->update($brand, $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Prompt::nullByteRule()],
            'primary_domain' => ['sometimes', 'required', 'string', 'max:255', 'regex:'.IdentityLibrary::DOMAIN],
            'status' => ['sometimes', 'required', Rule::in(['active', 'paused'])],
        ]));

        return back();
    }

    public function archive(Request $request, Brand $brand, IdentityLibrary $identity): RedirectResponse
    {
        Gate::authorize('update', $brand);
        $data = $request->validate(['brand_name_confirmation' => ['required', 'string', 'max:255']]);
        $identity->archive($brand, $data['brand_name_confirmation']);

        return redirect()->route('brands.index');
    }

    public function storeAlias(Request $request, Brand $brand, IdentityLibrary $identity): RedirectResponse
    {
        Gate::authorize('update', $brand);
        $data = $request->validate($this->aliasRules($request));
        $identity->saveAlias($brand, null, $data['alias'], $data['match_mode']);

        return back();
    }

    public function updateAlias(Request $request, Brand $brand, int $alias, IdentityLibrary $identity): RedirectResponse
    {
        Gate::authorize('update', $brand);
        $data = $request->validate(['match_mode' => ['required', Rule::in(self::MODES)]]);
        $identity->saveAlias($brand, $alias, null, $data['match_mode']);

        return back();
    }

    public function destroyAlias(Brand $brand, int $alias, IdentityLibrary $identity): RedirectResponse
    {
        Gate::authorize('update', $brand);
        $identity->deleteAlias($brand, $alias);

        return back();
    }

    public function storeDomain(Request $request, Brand $brand, IdentityLibrary $identity, UrlNormalizer $urls): RedirectResponse
    {
        Gate::authorize('update', $brand);
        $input = $request->input('domain');
        if (is_string($input)) {
            $request->merge(['domain' => $urls->normalize($input)['domain'] ?? null]);
        }
        $identity->saveDomain($brand, null, $request->validate([
            'domain' => ['required', 'string', 'max:255', 'regex:'.IdentityLibrary::DOMAIN],
            'include_subdomains' => ['required', 'boolean'],
        ]));

        return back();
    }

    public function updateDomain(Request $request, Brand $brand, int $domain, IdentityLibrary $identity): RedirectResponse
    {
        Gate::authorize('update', $brand);
        $identity->saveDomain($brand, $domain, $request->validate(['include_subdomains' => ['required', 'boolean']]));

        return back();
    }

    public function destroyDomain(Brand $brand, int $domain, IdentityLibrary $identity): RedirectResponse
    {
        Gate::authorize('update', $brand);
        $identity->deleteDomain($brand, $domain);

        return back();
    }

    public function integration(Request $request, Brand $brand, string $type, BrandIntegrations $integrations, IdentityLibrary $identity): RedirectResponse
    {
        Gate::authorize('update', $brand);
        $prefix = $type === 'ga4' ? 'ga4_' : 'clickhouse_';
        $rules = $integrations->rules();
        foreach (array_keys($rules) as $key) {
            if (! str_starts_with($key, $prefix)) {
                $rules[$key] = ['prohibited'];
            }
        }
        $identity->updateIntegration($brand, $request->validate($rules));

        return back();
    }

    public function testAlias(Request $request, Brand $brand, AliasMatchTest $matcher): JsonResponse
    {
        Gate::authorize('view', $brand);
        $data = $request->validate($this->aliasRules($request) + [
            'entity' => ['required', 'string', 'regex:/\A(?:brand|competitor:[1-9][0-9]{0,18})\z/'],
        ]);

        return response()->json($matcher->preview($brand, $data['entity'], $data['alias'], $data['match_mode']))
            ->header('Cache-Control', 'private, no-store');
    }

    /** @return array<string, mixed> */
    private function aliasRules(Request $request): array
    {
        return [
            'alias' => ['required', 'string', 'max:255', Prompt::nullByteRule(), Rule::when($request->input('match_mode') === 'regex', [new SafeRegexAlias])],
            'match_mode' => ['required', 'string', Rule::in(self::MODES)],
        ];
    }
}
