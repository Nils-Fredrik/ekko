<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MarketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Market extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MarketFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'country',
        'language',
        'location_label',
        'is_active',
    ];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<MarketEngine, $this> */
    public function marketEngines(): HasMany
    {
        return $this->hasMany(MarketEngine::class);
    }

    /** @return HasMany<Prompt, $this> */
    public function prompts(): HasMany
    {
        return $this->hasMany(Prompt::class);
    }

    /** @return HasMany<RunBatch, $this> */
    public function runBatches(): HasMany
    {
        return $this->hasMany(RunBatch::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
