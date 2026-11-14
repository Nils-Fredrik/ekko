<?php

namespace App\Models;

use App\Casts\EncryptedIntegrationConfig;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Integration extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<IntegrationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'type',
        'config',
        'last_sync_at',
        'status',
    ];

    /** @var list<string> */
    protected $hidden = [
        'config',
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
            'config' => EncryptedIntegrationConfig::class,
            'last_sync_at' => 'datetime',
        ];
    }
}
