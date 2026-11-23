<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $tenant_id */
class Brand extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'primary_domain',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        // Set when the queue of unknown brands is emptied by a decision (docs/ux-arbeidsflate.md 6.3 and 6.5).
        return ['entities_reviewed_at' => 'datetime'];
    }

    /** @param Builder<Brand> $query */
    public function scopeUnarchived(Builder $query): void
    {
        $query->where('brands.status', '!=', 'archived');
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->newQuery()->unarchived()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    /** @return HasMany<EntityDecision, $this> */
    public function entityDecisions(): HasMany
    {
        return $this->hasMany(EntityDecision::class);
    }

    /** @return HasMany<BrandAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(BrandAlias::class);
    }

    /** @return HasMany<BrandDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(BrandDomain::class);
    }

    /** @return HasMany<Competitor, $this> */
    public function competitors(): HasMany
    {
        return $this->hasMany(Competitor::class);
    }

    /**
     * The standard measurement plan; markets that inherit hold a copy (docs/spec.md 7.6, ADR 0042).
     *
     * @return HasMany<BrandPlanEngine, $this>
     */
    public function planEngines(): HasMany
    {
        return $this->hasMany(BrandPlanEngine::class);
    }

    /** @return HasMany<Market, $this> */
    public function markets(): HasMany
    {
        return $this->hasMany(Market::class);
    }

    /** @return HasMany<Topic, $this> */
    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class);
    }

    /** @return HasMany<BrandFact, $this> */
    public function facts(): HasMany
    {
        return $this->hasMany(BrandFact::class);
    }

    /** @return HasMany<Integration, $this> */
    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    /** @return HasMany<ReportSchedule, $this> */
    public function reportSchedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class);
    }

    /** @return HasMany<AlertRule, $this> */
    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }
}
