<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotLogUpload extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['brand_id', 'domain', 'payload', 'status'];

    /** @var list<string> */
    protected $hidden = ['payload'];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'encrypted'];
    }
}
