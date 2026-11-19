<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Insight text for one brand (or the overview) and one filter combination on one tenant-local day (docs/spec.md
 * 14.2, ADR 0038). `template` holds the model's text with {placeholders}; the figures are never stored here, they
 * are filled in from the database when the text is shown. `directions` records which way each change went when the
 * text was written (-1, 0 or 1), so the text is hidden if a figure has since turned the other way; `entities` records whom each movement
 * placeholder belongs to, so it is hidden too if the biggest movers have changed places.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $brand_id
 * @property string $scope
 * @property CarbonInterface $local_date
 * @property string $filter_key
 * @property array<string, mixed> $filter
 * @property string $locale
 * @property string $status
 * @property string|null $reason
 * @property string|null $template
 * @property array<string, int> $directions
 * @property array<string, string> $entities
 * @property string|null $model
 * @property int|null $usage_event_id
 * @property CarbonInterface $attempted_at
 */
class Insight extends Model
{
    use BelongsToTenant;

    public const WRITTEN = 'written';

    public const UNAVAILABLE = 'unavailable';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'local_date' => 'date',
            'filter' => 'array',
            'directions' => 'array',
            'entities' => 'array',
            'attempted_at' => 'datetime',
        ];
    }
}
