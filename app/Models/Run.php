<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property CarbonImmutable|null $enqueued_at
 * @property CarbonImmutable|null $provider_submitted_at
 * @property CarbonImmutable|null $next_poll_at
 * @property string|null $provider_api_key
 * @property string|null $provider_billed_to
 * @property string|null $provider_task_id
 * @property int $provider_submissions
 * @property int|null $provider_price_id
 * @property string|null $quota_reservation_token
 * @property CarbonImmutable|null $quota_reserved_at
 */
class Run extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RunFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'run_batch_id',
        'prompt_id',
        'engine_key',
        'repetition',
        'status',
        'attempts',
        'error_code',
        'started_at',
        'finished_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'provider_api_key',
        'quota_reservation_token',
        'quota_reserved_at',
        'quota_reserved_cost_usd',
        'quota_reserved_billed_to',
    ];

    /** @return BelongsTo<RunBatch, $this> */
    public function runBatch(): BelongsTo
    {
        return $this->belongsTo(RunBatch::class);
    }

    /** @return BelongsTo<Prompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    /** @return BelongsTo<Engine, $this> */
    public function engine(): BelongsTo
    {
        return $this->belongsTo(Engine::class, 'engine_key');
    }

    /** @return HasOne<Response, $this> */
    public function response(): HasOne
    {
        return $this->hasOne(Response::class, 'run_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider_api_key' => 'encrypted',
            'enqueued_at' => 'immutable_datetime',
            'provider_submitted_at' => 'immutable_datetime',
            'next_poll_at' => 'immutable_datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'quota_reserved_at' => 'immutable_datetime',
        ];
    }
}
