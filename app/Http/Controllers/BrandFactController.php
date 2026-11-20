<?php

namespace App\Http\Controllers;

use App\Dashboard\BrandPage;
use App\FactCheck\BrandFactManager;
use App\Models\Brand;
use App\Models\BrandFact;
use App\Models\Citation;
use App\Models\Claim;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The fact page of a brand (docs/spec.md 11.6, T49): the facts the answers are checked against and the claims the
 * fact check found, with the cited pages of the same answer. Facts change only through BrandFactManager (ADR 0036),
 * so the web page has the same limits and invalidation as the API.
 */
class BrandFactController extends Controller
{
    /** Claims shown on the page; the newest first. The API pages through all of them. */
    public const CLAIM_LIMIT = 100;

    public function index(Request $request, Brand $brand): Response
    {
        BrandPage::authorizeView($brand);
        Gate::authorize('viewAny', BrandFact::class);
        $verdict = $request->validate(['verdict' => ['nullable', Rule::in(Claim::VERDICTS)]])['verdict'] ?? Claim::CONTRADICTED;

        $counts = Claim::query()->where('brand_id', $brand->id)->whereNotNull('fact_id')
            ->selectRaw('fact_id, verdict, count(*) as total')->groupBy('fact_id', 'verdict')->get()
            ->groupBy('fact_id');
        $facts = BrandFact::query()->where('brand_id', $brand->id)->orderByDesc('is_active')->orderBy('id')->get()
            ->map(fn (BrandFact $fact): array => [
                'id' => $fact->id,
                'text' => $fact->text,
                'is_active' => $fact->is_active,
                'claims' => collect(Claim::VERDICTS)->mapWithKeys(fn (string $v): array => [
                    $v => (int) ($counts->get($fact->id)?->firstWhere('verdict', $v)?->getAttribute('total') ?? 0),
                ])->all(),
            ])->all();

        $claims = Claim::query()->where('brand_id', $brand->id)->where('verdict', $verdict)
            ->with(['fact:id,text', 'response:id,run_id,engine_key,collected_at', 'response.run:id,prompt_id'])
            ->orderByDesc('id')->limit(self::CLAIM_LIMIT)->get();
        $citations = Citation::query()
            ->whereIn('id', $claims->flatMap(fn (Claim $claim): array => $claim->source_citation_ids)->unique()->values())
            ->get(['id', 'response_id', 'url', 'domain', 'ownership'])->keyBy('id');

        return Inertia::render('Brands/Facts', [
            'brand' => BrandPage::payload($brand),
            'facts' => $facts,
            'maxFacts' => (int) config('ekko.facts.max_per_brand'),
            'maxLength' => BrandFact::TEXT_MAX,
            'verdict' => $verdict,
            'verdicts' => Claim::VERDICTS,
            'claimLimit' => self::CLAIM_LIMIT,
            'claims' => $claims->map(fn (Claim $claim): array => [
                'id' => $claim->id,
                'fact' => $claim->fact?->text,
                'text' => $claim->text,
                'quote' => $claim->quote,
                'verdict' => $claim->verdict,
                'engine' => $claim->response?->engine_key,
                'collected_at' => $claim->response?->collected_at?->toIso8601String(),
                'prompt_id' => $claim->response?->run?->prompt_id,
                // Only citations of the same answer: an id the model invented for another answer is never shown.
                'sources' => collect($claim->source_citation_ids)
                    ->map(fn (mixed $id): ?Citation => $citations->get((int) $id))
                    ->filter(fn (?Citation $citation): bool => $citation !== null && $citation->response_id === $claim->response_id)
                    ->map(fn (Citation $citation): array => $citation->only(['id', 'url', 'domain', 'ownership']))
                    ->values()->all(),
            ])->all(),
            'canManage' => Gate::allows('create', BrandFact::class),
        ]);
    }

    public function store(Request $request, Brand $brand, BrandFactManager $facts): RedirectResponse
    {
        BrandPage::authorizeView($brand);
        Gate::authorize('create', BrandFact::class);
        $data = $request->validate(['text' => ['required', 'string', 'max:'.BrandFact::TEXT_MAX]]);
        $facts->add($brand, $data['text']);

        return back(303);
    }

    public function update(Request $request, Brand $brand, BrandFact $fact, BrandFactManager $facts): RedirectResponse
    {
        BrandPage::authorizeView($brand);
        abort_unless($fact->brand_id === $brand->id, 404);
        Gate::authorize('update', $fact);
        $data = $request->validate([
            'text' => ['sometimes', 'required', 'string', 'max:'.BrandFact::TEXT_MAX],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $facts->update($fact, $data['text'] ?? null, isset($data['is_active']) ? (bool) $data['is_active'] : null);

        return back(303);
    }

    public function destroy(Brand $brand, BrandFact $fact, BrandFactManager $facts): RedirectResponse
    {
        BrandPage::authorizeView($brand);
        abort_unless($fact->brand_id === $brand->id, 404);
        Gate::authorize('delete', $fact);
        $facts->delete($fact);

        return back(303);
    }
}
