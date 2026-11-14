<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MarketEngineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketEngine extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MarketEngineFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'market_id',
        'engine_key',
        'frequency',
        'prompt_scope',
        'repetitions',
    ];

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
            'repetitions' => 'integer',
        ];
    }
}
