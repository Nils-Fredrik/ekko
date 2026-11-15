<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UsageEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $cost_usd Exact decimal:8 cast, never a binary float. */
class UsageEvent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<UsageEventFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'collection_attempt_token',
        'occurred_at',
        'kind',
        'engine_key',
        'calls',
        'input_tokens',
        'output_tokens',
        'search_calls',
        'cost_usd',
        'billed_to',
        'run_id',
    ];

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
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
            'occurred_at' => 'datetime',
            'cost_usd' => 'decimal:8',
        ];
    }
}
