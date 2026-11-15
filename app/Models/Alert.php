<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $alert_rule_id
 * @property string|null $subject
 * @property CarbonInterface $triggered_at
 * @property array<string, mixed> $payload
 * @property CarbonInterface|null $digest_date
 * @property array<string, mixed>|null $deliveries
 * @property CarbonInterface|null $delivered_at
 * @property int|null $acknowledged_by
 * @property CarbonInterface|null $acknowledged_at
 */
class Alert extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'alert_rule_id',
        'subject',
        'triggered_at',
        'payload',
        'digest_date',
        'deliveries',
        'delivered_at',
        'acknowledged_by',
        'acknowledged_at',
    ];

    /** @return BelongsTo<AlertRule, $this> */
    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class);
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'triggered_at' => 'datetime',
            'payload' => 'array',
            'digest_date' => 'date',
            'deliveries' => 'array',
            'delivered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }
}
