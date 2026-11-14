<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BotHitDailyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotHitDaily extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BotHitDailyFactory> */
    use HasFactory;

    protected $table = 'bot_hits_daily';

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'domain',
        'bot',
        'date',
        'hits',
        'unique_paths',
        'top_paths',
    ];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'top_paths' => 'array',
        ];
    }
}
