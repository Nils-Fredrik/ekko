<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'alert_rule_id',
        'triggered_at',
        'payload',
        'delivered_at',
        'acknowledged_by',
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
            'delivered_at' => 'datetime',
        ];
    }
}
