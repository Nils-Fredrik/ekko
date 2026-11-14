<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CompetitorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Competitor extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CompetitorFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'name',
        'is_active',
    ];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<CompetitorAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(CompetitorAlias::class);
    }

    /** @return HasMany<CompetitorDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(CompetitorDomain::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
