<?php

namespace App\Brands;

use App\MeasurementPlan\MeasurementPlans;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Team;
use App\Prompts\PromptLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MarketLibrary
{
    public function __construct(private MeasurementPlans $plans, private PromptLibrary $prompts) {}

    /** @param array<string, mixed> $data */
    public function create(Brand $brand, array $data): Market
    {
        return DB::transaction(function () use ($brand, $data): Market {
            // The wizard locks the same tenant before allocating a market slot.
            $tenant = Team::query()->lock('for no key update')->findOrFail(current_tenant_id());
            $brand = Brand::query()->lockForUpdate()->findOrFail($brand->id);
            $country = strtoupper($data['country']);
            $language = self::languageTag($data['language']);
            self::ensureAvailable($brand, $country, $language, $tenant->plan?->max_markets_per_brand);
            if ($this->plans->standardErrors($tenant, $brand) !== []) {
                throw ValidationException::withMessages(['plan_mode' => __('app.plan.standard_invalid')]);
            }
            $source = isset($data['copy_from']) ? $brand->markets()->lockForUpdate()->findOrFail($data['copy_from']) : null;
            if ($source !== null && strcasecmp($source->language, $language) !== 0) {
                throw ValidationException::withMessages(['copy_from' => __('app.workspace.copy_language')]);
            }
            $market = $brand->markets()->create([
                'country' => $country, 'language' => $language, 'location_label' => $data['location_label'] ?? null,
                'is_active' => true, 'plan_mode' => Market::PLAN_INHERIT,
            ]);
            $this->plans->inherit($market);
            if ($source !== null) {
                $cap = $tenant->plan?->max_prompts_per_market;
                $query = $source->prompts()->where('is_active', true)->whereNull('archived_at')
                    ->orderByRaw("case when priority = 'core' then 0 else 1 end")->orderBy('id');
                if ($cap !== null) {
                    $query->limit($cap);
                }
                foreach ($query->cursor() as $prompt) {
                    $this->prompts->saveLocked($market, null, $prompt->only(['text', 'topic_id', 'intent', 'funnel_stage', 'priority']));
                }
            }

            return $market;
        });
    }

    public static function languageTag(string $language): string
    {
        $parts = explode('-', $language);
        $parts[0] = strtolower($parts[0]);

        return implode('-', $parts);
    }

    public static function localeExists(Brand $brand, string $country, string $language): bool
    {
        return $brand->markets()->where('country', strtoupper($country))->whereRaw('lower(language) = ?', [strtolower($language)])->exists();
    }

    /** Both setup and wizard call this while holding the tenant's market allocation lock. */
    public static function ensureAvailable(Brand $brand, string $country, string $language, ?int $limit): void
    {
        if (self::localeExists($brand, $country, $language)) {
            throw ValidationException::withMessages(['country' => __('onboarding.errors.market_exists')]);
        }
        if ($limit !== null && $brand->markets()->count() >= $limit) {
            throw ValidationException::withMessages(['country' => __('onboarding.errors.market_limit')]);
        }
    }

    /** @param array<string, mixed> $changes */
    public function update(Market $market, array $changes): void
    {
        DB::transaction(fn () => Market::query()->lockForUpdate()->findOrFail($market->id)->fill($changes)->save());
    }
}
