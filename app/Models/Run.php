<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
