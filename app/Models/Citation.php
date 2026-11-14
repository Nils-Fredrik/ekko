<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Citation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CitationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'response_id',
        'url',
        'url_normalized',
        'domain',
        'ownership',
        'competitor_id',
        'source_type',
        'rank_in_answer',
        'from_annotation',
    ];

    /** @return BelongsTo<Response, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(Response::class);
    }

    /** @return BelongsTo<Competitor, $this> */
    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_annotation' => 'boolean',
        ];
    }
}
