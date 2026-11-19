<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AggSourceDailyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AggSourceDaily extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AggSourceDailyFactory> */
    use HasFactory;

    protected $table = 'agg_source_daily';

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'market_id',
        'engine_key',
        'domain',
        'date',
        'citations',
        'responses_with_domain',
        'gap_responses',
        'citation_distribution',
    ];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Market, $this> */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /** @return BelongsTo<Engine, $this> */
    public function engine(): BelongsTo
    {
        return $this->belongsTo(Engine::class, 'engine_key');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'citation_distribution' => 'array',
        ];
    }
}
