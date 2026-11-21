<?php

namespace App\Http\Controllers;

use App\Dashboard\BrandPage;
use App\Detection\AnswerExcerpt;
use App\Detection\EntityName;
use App\Models\Brand;
use App\Models\EntityDecision;
use App\Models\Mention;
use App\Prompts\EntityDecisions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Decisions on unknown brands (docs/ux-arbeidsflate.md 6.3, ADR 0043): decide one or more names, undo a decision
 * within 24 hours, and show how a name stands in the answers.
 */
class EntityDecisionController extends Controller
{
    public const EXCERPTS = 3;

    public function store(Request $request, Brand $brand, EntityDecisions $decisions): JsonResponse
    {
        Gate::authorize('create', EntityDecision::class);

        $data = $request->validate([
            'keys' => ['required', 'array', 'min:1', 'max:100'],
            'keys.*' => ['required', 'string', 'max:'.EntityName::MAX_LENGTH],
            'decision' => ['required', 'string', Rule::in(EntityDecision::DECISIONS)],
            'target_type' => ['nullable', 'required_if:decision,'.EntityDecision::ALIAS, 'string', Rule::in(['brand', 'competitor'])],
            'target_id' => ['nullable', 'required_if:decision,'.EntityDecision::ALIAS, 'integer'],
        ]);
        $alias = $data['decision'] === EntityDecision::ALIAS;

        // A key is sent as the queue gave it; the server's key is the authority, so a variant spelling also works.
        $keys = array_map(fn (string $key): string => EntityName::key($key), $data['keys']);
        $created = $decisions->decide(
            $brand, $keys, $data['decision'],
            $alias ? $data['target_type'] : null,
            $alias ? (int) $data['target_id'] : null,
            $request->user(),
        );

        return response()->json([
            'decisions' => array_map(fn (EntityDecision $decision): array => ['id' => $decision->id, 'key' => $decision->name_key], $created),
            'undo_ids' => array_map(fn (EntityDecision $decision): int => $decision->id, $created),
        ]);
    }

    public function destroy(Brand $brand, string $decision, EntityDecisions $decisions): JsonResponse
    {
        $record = EntityDecision::query()->where('brand_id', $brand->id)->findOrFail($decision);
        Gate::authorize('delete', $record);
        // The brand's own aliases are the brand's settings, as when the decision was made (ADR 0043).
        if ($record->target_type === 'brand') {
            Gate::authorize('update', $brand);
        }

        if (! $record->undoable()) {
            return response()->json([
                'code' => 'undo_expired',
                'message' => __('entities.errors.undo_expired', ['hours' => (int) config('ekko.entities.undo_hours')]),
            ], 422);
        }

        $decisions->undo($record);

        return response()->json(['undone' => $record->id]);
    }

    /**
     * Up to three excerpts from the newest answers of the brand's markets that name the key, with 80 characters on
     * each side. Answer text is shown as on the prompt page; it is neither logged nor cached, and no raw answer
     * reference leaves the server.
     */
    public function excerpts(Brand $brand, string $key): JsonResponse
    {
        BrandPage::authorizeView($brand);
        $key = EntityName::key($key);
        $since = CarbonImmutable::now('UTC')->subDays((int) config('ekko.entities.reclassify_days'));

        $answers = fn () => Mention::query()
            ->join('responses', 'responses.id', '=', 'mentions.response_id')
            ->join('runs', 'runs.id', '=', 'responses.run_id')
            ->join('prompts', 'prompts.id', '=', 'runs.prompt_id')
            ->join('markets', 'markets.id', '=', 'prompts.market_id')
            ->where('markets.brand_id', $brand->id)
            ->where('responses.collected_at', '>=', $since);

        $surfaces = $answers()->toBase()->distinct()->pluck('mentions.surface_name')
            ->map(fn ($name): string => (string) $name)
            ->filter(fn (string $name): bool => $key !== '' && EntityName::key($name) === $key)
            ->values()->all();

        $excerpts = [];
        if ($surfaces !== []) {
            $rows = $answers()->whereIn('mentions.surface_name', $surfaces)
                ->orderByDesc('responses.collected_at')->orderByDesc('responses.id')->orderBy('mentions.id')
                ->limit(50)
                ->toBase()
                ->get(['mentions.response_id', 'mentions.surface_name', 'mentions.first_char_offset', 'responses.answer_text',
                    'responses.engine_key', 'responses.collected_at', 'markets.id as market_id', 'markets.country', 'markets.location_label']);
            $seen = [];
            foreach ($rows as $row) {
                if (isset($seen[$row->response_id]) || count($excerpts) >= self::EXCERPTS) {
                    continue;
                }
                $excerpt = AnswerExcerpt::around((string) $row->answer_text, (string) $row->surface_name, $row->first_char_offset === null ? null : (int) $row->first_char_offset);
                if ($excerpt === null) {
                    continue;
                }
                $seen[$row->response_id] = true;
                $excerpts[] = $excerpt + [
                    'engine' => (string) $row->engine_key,
                    'market' => ['id' => (int) $row->market_id, 'label' => (string) ($row->location_label ?: strtoupper((string) $row->country))],
                    'collected_at' => CarbonImmutable::parse((string) $row->collected_at)->utc()->toIso8601String(),
                ];
            }
        }

        return response()->json(['key' => $key, 'excerpts' => $excerpts]);
    }
}
