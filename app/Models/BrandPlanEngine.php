<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BrandPlanEngineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rule in a brand's standard measurement plan (docs/spec.md 6.2 and 7.6, ADR 0042). Same shape as
 * market_engines: markets that inherit hold an exact copy of these rules.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $brand_id
 * @property string $engine_key
 * @property string $frequency
 * @property string $prompt_scope
 * @property int $repetitions
 */
class BrandPlanEngine extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BrandPlanEngineFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'engine_key',
        'frequency',
        'prompt_scope',
        'repetitions',
    ];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
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
