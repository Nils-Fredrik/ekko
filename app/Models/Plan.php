<?php

namespace App\Models;

use App\Observers\PlanObserver;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A package defined by the platform admin (docs/spec.md 6.1 and 12.1). A null limit means
 * unlimited unless a tenant has its own call quota.
 *
 * @property int $id
 * @property string $name
 * @property int|null $max_brands
 * @property int|null $max_markets_per_brand
 * @property int|null $max_prompts_per_market
 * @property list<string> $allowed_engines
 * @property array<string, string> $engine_max_frequencies
 * @property string $max_frequency
 * @property int $max_repetitions
 * @property int|null $monthly_call_quota
 * @property string|null $monthly_budget_usd
 */
#[ObservedBy(PlanObserver::class)]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'max_brands',
        'max_markets_per_brand',
        'max_prompts_per_market',
        'allowed_engines',
        'max_frequency',
        'engine_max_frequencies',
        'max_repetitions',
        'monthly_call_quota',
        'monthly_budget_usd',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'allowed_engines' => '[]',
        'max_frequency' => 'daily',
        'engine_max_frequencies' => '{}',
        'max_repetitions' => 1,
    ];

    public function allowsEngine(string $engineKey): bool
    {
        return in_array($engineKey, $this->allowed_engines, true);
    }

    public function maxFrequencyForEngine(string $engineKey): string
    {
        return $this->engine_max_frequencies[$engineKey] ?? $this->max_frequency;
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_brands' => 'integer',
            'max_markets_per_brand' => 'integer',
            'max_prompts_per_market' => 'integer',
            'allowed_engines' => 'array',
            'engine_max_frequencies' => 'array',
            'max_repetitions' => 'integer',
            'monthly_call_quota' => 'integer',
            'monthly_budget_usd' => 'decimal:2',
            'is_pitch_plan' => 'boolean',
        ];
    }
}
