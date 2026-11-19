<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsRevisions;
use Carbon\CarbonInterface;
use Database\Factories\BrandFactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fact about a brand that the answers are checked against (docs/spec.md 11.6, ADR 0036). Change facts through
 * BrandFactManager, which drops the claims judged against the old text.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $brand_id
 * @property string $text
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class BrandFact extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BrandFactFactory> */
    use HasFactory;

    use RecordsRevisions;

    public const TEXT_MAX = 300;

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'text',
        'is_active',
    ];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<Claim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class, 'fact_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return list<string> */
    protected function revisionAttributes(): array
    {
        return ['brand_id', 'is_active'];
    }

    /**
     * The text is the customer's own wording: logged as changed, never copied into the log.
     *
     * @return list<string>
     */
    protected function maskedRevisionAttributes(): array
    {
        return ['text'];
    }
}
