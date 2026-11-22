<?php

namespace App\Http\Controllers;

use App\Dashboard\AnalysisFilters;
use App\Dashboard\BrandPage;
use App\Dashboard\DashboardLabels;
use App\Dashboard\DashboardPeriod;
use App\Dashboard\SentimentResponses;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Mention;
use App\Prompts\AnswerSegments;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The answers behind the sentiment on the competitors page (U28): the brand's or a competitor's mentions with a
 * negative, neutral or positive sentiment, in the period and filters of the analysis pages.
 */
class BrandResponseController extends Controller
{
    public const PAGE = 'brands.responses.index';

    public function index(Request $request, Brand $brand): Response
    {
        BrandPage::authorizeView($brand);
        $input = Validator::make($request->query(), [
            'entity' => ['nullable', 'string', 'max:20'],
            'sentiment' => ['nullable', Rule::in(SentimentResponses::SENTIMENTS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $competitor = $this->competitor($brand, $input['entity'] ?? null);
        $query = AnalysisFilters::query($brand, $request->query(), self::PAGE);
        $period = DashboardPeriod::fromInput($query, $brand->id);
        $filter = $period->filter($brand->id, ...AnalysisFilters::selection($query));
        $responses = new SentimentResponses(
            $filter,
            $period->timezone,
            $competitor === null ? 'brand' : 'competitor',
            $competitor === null ? $brand->id : $competitor->id,
        );
        $sentiment = $input['sentiment'] ?? null;
        $page = $responses->page($sentiment, (int) ($input['page'] ?? 1));
        $mentions = collect($page->items());
        $caseFold = AnswerSegments::caseFoldFor($brand);
        $labels = DashboardLabels::engines($mentions->map(fn (Mention $m) => (string) $m->response?->engine_key)->unique()->values()->all());

        return Inertia::render('Brands/Responses', [
            'brand' => BrandPage::payload($brand),
            'period' => $period->toArray(),
            'analysisFilters' => AnalysisFilters::payload($brand, self::PAGE, $query, $request->query()),
            'entity' => [
                'key' => $competitor === null ? 'brand' : (string) $competitor->id,
                'name' => $competitor === null ? $brand->name : $competitor->name,
                'own' => $competitor === null,
            ],
            'entities' => [
                ['key' => 'brand', 'name' => $brand->name, 'own' => true],
                ...Competitor::query()->where('brand_id', $brand->id)->where('is_active', true)->inColorOrder()->get()
                    ->map(fn (Competitor $c): array => ['key' => (string) $c->id, 'name' => $c->name, 'own' => false])->all(),
            ],
            'sentiment' => $sentiment,
            'counts' => $responses->counts(),
            'answers' => $mentions->map(fn (Mention $mention): array => $this->answer($brand, $mention, $labels, $caseFold, $period->timezone))->values()->all(),
            'pagination' => [
                'page' => $page->currentPage(),
                'last' => $page->lastPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    /**
     * The competitor named by `entity`, or null for the brand itself. A competitor of another brand or another
     * tenant is not found, and neither is an inactive one, since the competitors table and the entity list leave
     * those out.
     */
    private function competitor(Brand $brand, ?string $entity): ?Competitor
    {
        if ($entity === null || $entity === '' || $entity === 'brand') {
            return null;
        }
        abort_unless(ctype_digit($entity), 404);

        return Competitor::query()->where('brand_id', $brand->id)->where('is_active', true)->whereKey((int) $entity)->firstOrFail();
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, true>  $caseFold
     * @return array<string, mixed>
     */
    private function answer(Brand $brand, Mention $mention, array $labels, array $caseFold, string $timezone): array
    {
        $response = $mention->response;
        $prompt = $response?->run?->prompt;
        $text = (string) $response?->answer_text;
        $collected = $response?->collected_at;
        $engine = (string) $response?->engine_key;

        return [
            'id' => $mention->id,
            'response_id' => $response?->id,
            'sentiment' => $mention->sentiment === null ? null : (float) $mention->sentiment,
            'recommended' => (bool) $mention->is_recommended,
            'collected_at' => $collected instanceof CarbonInterface ? $collected->toIso8601String() : '',
            'date' => $collected instanceof CarbonInterface ? $collected->copy()->timezone($timezone)->toDateString() : '',
            'engine' => $labels[$engine] ?? DashboardLabels::engine($engine),
            'prompt' => $prompt === null ? null : [
                'id' => $prompt->id,
                'text' => $prompt->text,
                'url' => route('brands.prompts.show', [$brand, $prompt]),
            ],
            'excerpt' => SentimentResponses::excerpt($text, (string) $mention->surface_name,
                $mention->first_char_offset === null ? null : (int) $mention->first_char_offset),
            'segments' => AnswerSegments::from($text, $response->mentions ?? [], $caseFold),
            'raw' => $response === null ? null : route('brands.responses.raw', [$brand, $response]),
        ];
    }
}
