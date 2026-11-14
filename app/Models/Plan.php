<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A package defined by the platform admin (docs/spec.md 6.1 and 12.1). A null limit means
 * unlimited, or set per tenant. Quotas and usage are built on this in T19.
 *
 * @property int $id
 * @property string $name
 * @property int|null $max_brands
 * @property int|null $max_markets_per_brand
 * @property int|null $max_prompts_per_market
 * @property list<string> $allowed_engines
 * @property string $max_frequency
 * @property int $max_repetitions
 * @property int|null $monthly_call_quota
 * @property string|null $monthly_budget_usd
 */
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
        'max_repetitions' => 1,
    ];

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
            'max_repetitions' => 'integer',
            'monthly_call_quota' => 'integer',
            'monthly_budget_usd' => 'decimal:2',
        ];
    }
}
