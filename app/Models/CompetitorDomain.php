<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\RecordsRevisions;
use Database\Factories\CompetitorDomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitorDomain extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CompetitorDomainFactory> */
    use HasFactory;

    use RecordsRevisions;

    /** @var list<string> */
    protected $fillable = [
        'competitor_id',
        'domain',
    ];

    /** @return BelongsTo<Competitor, $this> */
    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    /** @return list<string> */
    protected function revisionAttributes(): array
    {
        return ['competitor_id', 'domain'];
    }
}
