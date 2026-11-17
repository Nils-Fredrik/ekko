<?php

namespace App\Models;

use Database\Factories\EnginePriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $valid_from
 * @property string $price_per_call_usd
 * @property string $price_per_1k_input_tokens_usd
 * @property string $price_per_1k_output_tokens_usd
 * @property string $price_per_search_usd
 */
class EnginePrice extends Model
{
    /** @use HasFactory<EnginePriceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'engine_key',
        'valid_from',
        'price_per_call_usd',
        'price_per_1k_input_tokens_usd',
        'price_per_1k_output_tokens_usd',
        'price_per_search_usd',
    ];

    /** @return BelongsTo<Engine, $this> */
    public function engine(): BelongsTo
    {
        return $this->belongsTo(Engine::class, 'engine_key');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'price_per_call_usd' => 'decimal:8',
            'price_per_1k_input_tokens_usd' => 'decimal:8',
            'price_per_1k_output_tokens_usd' => 'decimal:8',
            'price_per_search_usd' => 'decimal:8',
        ];
    }
}
