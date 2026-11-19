<?php

namespace App\FactCheck;

use App\Models\Brand;
use App\Models\BrandFact;
use App\Models\Claim;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one way to add, change, switch off and remove the facts a brand is checked against (docs/spec.md 11.6,
 * ADR 0036). The UI (T49) and the API and MCP tools (T50) call it, and the policy (BrandFactPolicy) decides who
 * may. Runs in the tenant's context.
 *
 * A claim is judged against the text its fact had when the answer was extracted. When a fact's text changes or the
 * fact is switched off its claims are deleted, so an old verdict never counts against a new text; the answers are
 * judged again when they are extracted again. An action built on those claims stops being supported and
 * expires by the usual rule (ADR 0034).
 */
final class BrandFactManager
{
    public function add(Brand $brand, string $text, bool $active = true): BrandFact
    {
        $text = $this->clean($text);

        return DB::transaction(function () use ($brand, $text, $active): BrandFact {
            // One brand at a time, so two requests cannot both pass the limit and the duplicate check.
            Brand::query()->lock('for update')->findOrFail($brand->id);
            if (BrandFact::query()->where('brand_id', $brand->id)->count() >= (int) config('ekko.facts.max_per_brand')) {
                throw ValidationException::withMessages(['text' => __('fact_check.errors.limit', ['max' => (int) config('ekko.facts.max_per_brand')])]);
            }
            $this->guardDuplicate($brand->id, $text, null);

            return BrandFact::query()->create(['brand_id' => $brand->id, 'text' => $text, 'is_active' => $active]);
        });
    }

    /**
     * @param  string|null  $text  the new text, or null to keep it
     * @param  bool|null  $active  switch on or off, or null to keep it
     */
    public function update(BrandFact $fact, ?string $text = null, ?bool $active = null): BrandFact
    {
        return DB::transaction(function () use ($fact, $text, $active): BrandFact {
            $fact = BrandFact::query()->lockForUpdate()->findOrFail($fact->id);
            $changed = false;
            if ($text !== null && $this->clean($text) !== $fact->text) {
                $text = $this->clean($text);
                $this->guardDuplicate($fact->brand_id, $text, $fact->id);
                $fact->text = $text;
                $changed = true;
            }
            if ($active !== null && $active !== $fact->is_active) {
                $fact->is_active = $active;
                $changed = true;
            }
            if ($changed) {
                $fact->save();
                // New text or off: what was judged against the old text no longer says anything.
                Claim::query()->where('fact_id', $fact->id)->delete();
            }

            return $fact;
        });
    }

    /** Deleting a fact deletes its claims with it (foreign key). */
    public function delete(BrandFact $fact): void
    {
        $fact->delete();
    }

    private function clean(string $text): string
    {
        $text = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', mb_scrub($text)));
        if ($text === '') {
            throw ValidationException::withMessages(['text' => __('fact_check.errors.empty')]);
        }
        if (mb_strlen($text) > BrandFact::TEXT_MAX) {
            throw ValidationException::withMessages(['text' => __('fact_check.errors.too_long', ['max' => BrandFact::TEXT_MAX])]);
        }

        return $text;
    }

    private function guardDuplicate(int $brandId, string $text, ?int $exceptId): void
    {
        $exists = BrandFact::query()->where('brand_id', $brandId)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->whereRaw('lower(text) = ?', [mb_strtolower($text)])->exists();
        if ($exists) {
            throw ValidationException::withMessages(['text' => __('fact_check.errors.duplicate')]);
        }
    }
}
