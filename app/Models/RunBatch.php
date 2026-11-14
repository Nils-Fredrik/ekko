<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\RunBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RunBatch extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RunBatchFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'market_id',
        'scheduled_for',
        'status',
        'planned_runs',
        'completed_runs',
        'failed_runs',
    ];

    /** @return BelongsTo<Market, $this> */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    /** @return HasMany<Run, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
        ];
    }
}
