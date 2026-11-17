<?php

namespace App\Models;

use Database\Factories\EngineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Engine extends Model
{
    /** @use HasFactory<EngineFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'key';

    /** @var list<string> */
    protected $fillable = [
        'key',
        'family',
        'method',
        'provider',
        'model_id',
        'config',
        'is_enabled',
    ];

    public function isAvailable(): bool
    {
        return $this->is_enabled
            && ! in_array($this->provider, config('engines.disabled_providers', []), true)
            && ($this->key !== 'gemini_api' || ! app()->environment('production')
                || config('engines.gemini_grounding_approved', false));
    }

    /** @return HasMany<EnginePrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(EnginePrice::class, 'engine_key');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_enabled' => 'boolean',
        ];
    }
}
