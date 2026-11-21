<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsRevisions;
use Database\Factories\CompetitorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Competitor extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CompetitorFactory> */
    use HasFactory;

    use RecordsRevisions;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'name',
        'is_active',
        'position',
    ];

    protected static function booted(): void
    {
        // A new competitor takes the next place, so the colors of the others do not change (ADR 0043).
        static::creating(function (Competitor $competitor): void {
            if ((int) $competitor->position === 0) {
                $competitor->position = self::nextPosition((int) $competitor->brand_id);
            }
        });
    }

    /** The place after every competitor of the brand, archived ones included. */
    public static function nextPosition(int $brandId): int
    {
        return (int) self::withTrashed()->where('brand_id', $brandId)->max('position') + 1;
    }

    /**
     * The fixed color place of each active competitor of the brand (docs/ux-arbeidsflate.md 6.3): its index among
     * the active competitors by position, then id. Every page, chart, report and API that shows a competitor's
     * color reads it from here.
     *
     * @return array<int, int> competitor id => color index (0-based)
     */
    public static function colorIndexes(Brand|int $brand): array
    {
        $ids = self::query()->where('brand_id', $brand instanceof Brand ? $brand->id : $brand)
            ->where('is_active', true)->inColorOrder()->pluck('id');

        return $ids->values()->mapWithKeys(fn ($id, int $index): array => [(int) $id => $index])->all();
    }

    /**
     * The color order: position, then id.
     *
     * @param  Builder<Competitor>  $query
     * @return Builder<Competitor>
     */
    public function scopeInColorOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

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
            'position' => 'integer',
        ];
    }

    /** @return list<string> */
    protected function revisionAttributes(): array
    {
        return ['brand_id', 'name', 'is_active', 'position'];
    }
}
