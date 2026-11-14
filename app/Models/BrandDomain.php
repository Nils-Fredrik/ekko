<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BrandDomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandDomain extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BrandDomainFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'domain',
        'include_subdomains',
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
            'include_subdomains' => 'boolean',
        ];
    }
}
