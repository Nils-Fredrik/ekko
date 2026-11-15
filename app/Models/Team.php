<?php

namespace App\Models;

use App\Enums\TenantStatus;
use App\Tenancy\InvalidPartnerException;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;

/**
 * A tenant (docs/spec.md 5.1): a closed customer unit with its own data, users, quotas and billing.
 * Technically a Jetstream team. A partner tenant manages client tenants, one level deep.
 *
 * partner_team_id, plan_id, status and byok_enabled are set by the platform admin and are
 * deliberately not mass assignable.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $partner_team_id
 * @property int|null $plan_id
 * @property TenantStatus $status
 * @property string $default_locale
 * @property string $timezone
 * @property array<string, mixed>|null $branding
 * @property string|null $slack_webhook_url
 * @property bool $byok_enabled
 * @property int|null $monthly_call_quota_override
 * @property string|null $monthly_byok_budget_usd
 */
class Team extends JetstreamTeam
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'personal_team',
    ];

    /**
     * Never sent to the frontend: Jetstream shares the current team and all teams with Vue.
     *
     * @var list<string>
     */
    protected $hidden = [
        'slack_webhook_url',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'default_locale' => 'nb',
        'timezone' => 'Europe/Oslo',
        'byok_enabled' => false,
    ];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (Team $team): void {
            $team->ensureValidPartner();
        });
    }

    /**
     * The partner tenant that manages this tenant.
     *
     * @return BelongsTo<Team, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'partner_team_id');
    }

    /**
     * The client tenants this partner tenant manages.
     *
     * @return HasMany<Team, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Team::class, 'partner_team_id');
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isPartner(): bool
    {
        return $this->clients()->exists();
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    /**
     * A partner relation is at most one level deep: a partner cannot itself have a partner,
     * and a tenant with clients cannot get a partner (docs/spec.md 5.1).
     *
     * @throws InvalidPartnerException
     */
    protected function ensureValidPartner(): void
    {
        if ($this->partner_team_id === null || ! $this->isDirty('partner_team_id')) {
            return;
        }

        if ($this->exists && $this->partner_team_id === $this->id) {
            throw InvalidPartnerException::self();
        }

        $partner = Team::query()->find($this->partner_team_id);

        if ($partner === null) {
            throw InvalidPartnerException::missing($this->partner_team_id);
        }

        if ($partner->partner_team_id !== null) {
            throw InvalidPartnerException::nested();
        }

        if ($this->exists && $this->isPartner()) {
            throw InvalidPartnerException::hasClients();
        }
    }

    /**
     * Get the owner of the team.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get all of the pending user invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function teamInvitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
            'status' => TenantStatus::class,
            'branding' => 'array',
            'slack_webhook_url' => 'encrypted',
            'byok_enabled' => 'boolean',
            'monthly_call_quota_override' => 'integer',
            'monthly_byok_budget_usd' => 'decimal:2',
        ];
    }

    /**
     * Case-insensitive, unlike Jetstream's: addresses are compared the same way everywhere.
     *
     * @param  string  $email
     */
    public function hasUserWithEmail($email): bool
    {
        return $this->allUsers()->contains(fn ($user) => strcasecmp((string) $user->email, $email) === 0);
    }
}
