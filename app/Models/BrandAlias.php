<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BrandAliasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandAlias extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BrandAliasFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'alias',
        'match_mode',
    ];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
