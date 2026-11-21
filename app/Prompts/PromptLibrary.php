<?php

namespace App\Prompts;

use App\Models\Brand;
use App\Models\Market;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\Topic;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PromptLibrary
{
    /** @return array<string, mixed> */
    public static function rules(bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';

        return [
            'text' => [$presence, 'required', 'string', 'max:'.Prompt::TEXT_MAX, Prompt::nullByteRule()],
            'intent' => [$presence, 'required', Rule::in(Prompt::INTENTS)],
            'funnel_stage' => [$presence, 'required', Rule::in(Prompt::FUNNELS)],
            'priority' => ['sometimes', 'required', Rule::in(Prompt::PRIORITIES)],
            'is_active' => ['sometimes', 'boolean'],
            'topic_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(Market $market, array $data, string $limitField = 'is_active'): Prompt
    {
        return $this->withinLimits([$market->id], fn () => $this->saveLocked($market, null, $data), $limitField);
    }

    /** @param array<string, mixed> $data */
    public function update(Prompt $prompt, array $data, string $limitField = 'is_active'): Prompt
    {
        $market = $prompt->market()->firstOrFail();

        return $this->withinLimits([$market->id], fn () => $this->saveLocked($market, $prompt, $data), $limitField);
    }

    /**
     * Check final counts after the entire mutation, including MCP requests that swap active prompts.
     *
     * @template T
     *
     * @param  list<int>  $marketIds
     * @param  Closure(): T  $write
     * @return T
     */
    public function withinLimits(array $marketIds, Closure $write, string $limitField = 'is_active'): mixed
    {
        return DB::transaction(function () use ($marketIds, $write, $limitField) {
            $markets = Market::query()->whereKey($marketIds)->orderBy('id')->lockForUpdate()->get();
            abort_unless($markets->count() === count(array_unique($marketIds)), 404);
            $limit = Team::query()->findOrFail(current_tenant_id())->plan?->max_prompts_per_market;
            $counts = static fn () => Prompt::query()->whereIn('market_id', $marketIds)->where('is_active', true)
                ->groupBy('market_id')->selectRaw('market_id, count(*) as total')->pluck('total', 'market_id');
            $before = $limit === null ? collect() : $counts();
            $result = $write();
            if ($limit !== null) {
                foreach ($counts() as $id => $after) {
                    if ($after > $limit && $after > $before->get($id, 0)) {
                        throw ValidationException::withMessages([$limitField => __('app.library.quota')]);
                    }
                }
            }

            return $result;
        });
    }

    /**
     * Shared write path for callers holding the market lock.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveLocked(Market $market, ?Prompt $existing, array $data): Prompt
    {
        $prompt = $existing === null ? new Prompt(['market_id' => $market->id, 'is_active' => true, 'priority' => 'standard'])
            : Prompt::query()->where('market_id', $market->id)->lockForUpdate()->findOrFail($existing->id);
        if (isset($data['topic_id'])) {
            Topic::query()->where('brand_id', $market->brand_id)->sharedLock()->findOrFail($data['topic_id']);
        }
        if (isset($data['text'])) {
            $data['text_hash'] = Prompt::hashText($data['text']);
            if (Prompt::query()->where('market_id', $market->id)->where('text_hash', $data['text_hash'])
                ->when($existing !== null, fn ($query) => $query->whereKeyNot($prompt->id))->exists()) {
                throw ValidationException::withMessages(['text' => __('app.library.duplicate')]);
            }
        }
        $prompt->fill($data);
        if ($prompt->archived_at !== null && $prompt->is_active) {
            throw ValidationException::withMessages(['is_active' => __('app.library.archived_prompt')]);
        }
        $prompt->save();

        return $prompt;
    }

    public function delete(Prompt $prompt, bool $includingHistory = false): void
    {
        DB::transaction(function () use ($prompt, $includingHistory): void {
            Market::query()->lockForUpdate()->findOrFail($prompt->market_id);
            $locked = Prompt::query()->lockForUpdate()->findOrFail($prompt->id);
            if (! $includingHistory && $locked->runs()->exists()) {
                throw PromptHasRuns::withMessages(['prompt_has_runs' => __('app.library.prompt_has_runs')]);
            }
            $locked->delete();
        });
    }

    /**
     * @param  list<int>  $ids
     */
    public function bulk(Brand $brand, array $ids, string $action, mixed $value): BulkResult
    {
        return app(PromptBulk::class)->apply($brand, $ids, $action, $value);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payload(Brand $brand, ?Market $market, array $filters = []): array
    {
        return app(PromptLibraryView::class)->payload($brand, $market, $filters);
    }
}
