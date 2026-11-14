<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Tenancy\TenantAccess;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasTeams;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable. is_platform_admin is deliberately not mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    public function isPlatformAdmin(): bool
    {
        return $this->is_platform_admin === true;
    }

    /**
     * Switch the current tenant (the tenant switcher, docs/spec.md 5.4). Unlike Jetstream, a user
     * may also switch to tenants reached through a partner, or to the tenant a platform admin acts as.
     *
     * @param  Team  $team
     */
    public function switchTeam($team): bool
    {
        if (! app(TenantAccess::class)->canAccess($this, $team)) {
            return false;
        }

        $this->forceFill(['current_team_id' => $team->id])->save();
        $this->setRelation('currentTeam', $team);

        return true;
    }

    /**
     * The tenants in the switcher: memberships and partner clients (TenantAccess).
     *
     * @return Collection<int, Team>
     */
    public function allTeams(): Collection
    {
        return app(TenantAccess::class)->accessibleTenants($this);
    }

    /**
     * Get all of the teams the user owns.
     *
     * @return HasMany<Team, $this>
     */
    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * Get all of the teams the user belongs to.
     *
     * @return BelongsToMany<Team, $this, Membership, 'membership'>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, Membership::class)
            ->using(Membership::class)
            ->withPivot('role')
            ->withTimestamps()
            ->as('membership');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }
}
