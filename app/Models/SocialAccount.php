<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single sign-on account (Google for now) linked to a user (docs/spec.md 5.4).
 */
class SocialAccount extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'provider_user_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
