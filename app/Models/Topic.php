<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TopicFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Topic extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TopicFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'name',
        'cross_market_key',
    ];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<Prompt, $this> */
    public function prompts(): HasMany
    {
        return $this->hasMany(Prompt::class);
    }
}
