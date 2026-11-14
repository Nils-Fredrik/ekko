<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
