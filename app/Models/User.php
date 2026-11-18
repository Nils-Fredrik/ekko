<?php

namespace App\Models;

use App\Enums\TenantRole;
use App\Tenancy\TenantAccess;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * @property string|null $locale
 */
class User extends Authenticatable implements MustVerifyEmail
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

    /**
     * E-mail addresses are stored trimmed and lower case, so lookups and uniqueness do not depend
     * on case (ADR 0008).
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::lower(trim($value)));
    }

    public function isPlatformAdmin(): bool
    {
        return $this->is_platform_admin === true;
    }

    /**
     * Whether this user must use two-factor authentication: the platform admin and partner
     * advisors (docs/spec.md 5.4), unless the requirement is switched off (ekko.require_two_factor). Optional
     * for everyone else.
     */
    public function requiresTwoFactor(): bool
    {
        if (! config('ekko.require_two_factor')) {
            return false;
        }

        return $this->isPlatformAdmin()
            || $this->teams()->wherePivot('role', TenantRole::PartnerAdvisor->value)->exists();
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * The user's own API tokens (Jetstream's API token page). The access tokens of Claude connections (ability
     * "mcp", ADR 0016) are left out: they are managed under "Claude" and cannot be renamed or given API rights.
     *
     * @return MorphMany<PersonalAccessToken, $this>
     */
    public function tokens(): MorphMany
    {
        return $this->morphMany(Sanctum::$personalAccessTokenModel, 'tokenable')
            ->where(fn ($query) => $query->whereNull('abilities')->orWhereRaw("NOT (abilities::jsonb @> '[\"mcp\"]'::jsonb)"));
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Switch the current tenant (the tenant switcher, docs/spec.md 5.4). Unlike Jetstream, a user
     * may also switch to tenants reached through a partner. A tenant a platform admin only acts as
     * is never stored (acting-as is session-only).
     *
     * @param  Team|null  $team
     */
    public function switchTeam($team): bool
    {
        // Jetstream calls this with personalTeam(), which is null for invited users without one.
        // canSelect, not canAccess: a tenant reached only by acting as it is never stored.
        if (! $team instanceof Team || ! app(TenantAccess::class)->canSelect($this, $team)) {
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
