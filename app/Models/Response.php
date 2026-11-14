<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ResponseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Response extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ResponseFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'run_id',
        'engine_key',
        'model_id_reported',
        'answer_text',
        'answer_language',
        'raw_uri',
        'input_tokens',
        'output_tokens',
        'search_calls',
        'cost_usd',
        'collected_at',
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

    /** @return HasMany<Mention, $this> */
    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class);
    }

    /** @return HasMany<Citation, $this> */
    public function citations(): HasMany
    {
        return $this->hasMany(Citation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:8',
            'collected_at' => 'datetime',
        ];
    }
}
