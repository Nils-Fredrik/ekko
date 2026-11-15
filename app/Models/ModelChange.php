<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ModelChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A model change marker (docs/spec.md 7.5): the day the model id an engine reports changed, so jumps in
 * the figures can be explained. Written by the daily aggregation.
 */
class ModelChange extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ModelChangeFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'engine_key',
        'changed_on',
        'from_model',
        'to_model',
        'changed_at',
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
            'changed_on' => 'date',
            'changed_at' => 'datetime',
        ];
    }
}
