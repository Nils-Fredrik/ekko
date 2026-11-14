<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Jetstream\TeamInvitation as JetstreamTeamInvitation;

class TeamInvitation extends JetstreamTeamInvitation
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'role',
    ];

    /**
     * The moment the invitation stops being valid (docs/spec.md 5.4: 7 days).
     */
    public function expiresAt(): CarbonInterface
    {
        return $this->created_at->copy()->addDays((int) config('ekko.invitation_ttl_days'));
    }

    public function isExpired(): bool
    {
        return $this->expiresAt()->isPast();
    }

    /**
     * Invitations that can still be accepted.
     *
     * @param  Builder<TeamInvitation>  $query
     * @return Builder<TeamInvitation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('created_at', '>', now()->subDays((int) config('ekko.invitation_ttl_days')));
    }

    /**
     * Get the team that the invitation belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
