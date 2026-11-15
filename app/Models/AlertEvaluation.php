<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The alert evaluation of one tenant-local day (docs/spec.md 11.1). The row is the claim that makes the
 * evaluation and the digest run once a day.
 *
 * @property CarbonInterface $local_date
 * @property CarbonInterface $evaluated_at
 */
class AlertEvaluation extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = [
        'local_date',
        'evaluated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'local_date' => 'date',
            'evaluated_at' => 'datetime',
        ];
    }
}
