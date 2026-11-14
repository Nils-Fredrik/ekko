<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ExtractionJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExtractionJob extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ExtractionJobFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'provider_batch_id',
        'status',
        'response_ids',
        'submitted_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_ids' => 'array',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
