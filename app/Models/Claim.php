<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A claim about the brand in one answer, judged against one active fact (docs/spec.md 11.6, ADR 0036). Written by
 * the extraction (ExtractionWriter), never by a user. source_citation_ids are the citations of the same answer that
 * the model says repeat the claim; they are only set for contradicted claims.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $response_id
 * @property int $brand_id
 * @property int|null $fact_id
 * @property string $text
 * @property string|null $quote
 * @property string $verdict
 * @property list<int> $source_citation_ids
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Claim extends Model
{
    use BelongsToTenant;

    public const SUPPORTED = 'supported';

    public const CONTRADICTED = 'contradicted';

    public const UNRELATED = 'unrelated';

    public const VERDICTS = [self::SUPPORTED, self::CONTRADICTED, self::UNRELATED];

    /** @var list<string> */
    protected $fillable = [
        'response_id',
        'brand_id',
        'fact_id',
        'text',
        'quote',
        'verdict',
        'source_citation_ids',
    ];

    /** @return BelongsTo<Response, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(Response::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<BrandFact, $this> */
    public function fact(): BelongsTo
    {
        return $this->belongsTo(BrandFact::class, 'fact_id');
    }

    /**
     * @param  Builder<Claim>  $query
     * @return Builder<Claim>
     */
    public function scopeContradicted(Builder $query): Builder
    {
        return $query->where('verdict', self::CONTRADICTED);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_citation_ids' => 'array',
        ];
    }
}
