<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AggDailyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AggDaily extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AggDailyFactory> */
    use HasFactory;

    protected $table = 'agg_daily';

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'market_id',
        'engine_key',
        'prompt_id',
        'date',
        'responses',
        'brand_mentioned',
        'brand_recommended',
        'sum_position',
        'count_position',
        'sum_sentiment',
        'own_cited',
        'competitor_mentions',
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

    /** @return BelongsTo<Prompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sum_sentiment' => 'decimal:3',
            'competitor_mentions' => 'array',
        ];
    }
}
