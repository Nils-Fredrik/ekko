<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MentionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mention extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MentionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'response_id',
        'entity_type',
        'entity_id',
        'surface_name',
        'first_char_offset',
        'list_position',
        'is_recommended',
        'sentiment',
        'detection',
    ];

    /** @return BelongsTo<Response, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(Response::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_recommended' => 'boolean',
            'sentiment' => 'decimal:3',
        ];
    }
}
