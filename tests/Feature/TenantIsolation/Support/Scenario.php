<?php

namespace Tests\Feature\TenantIsolation\Support;

use App\Models\Brand;
use App\Models\McpConnection;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Mcp\McpTestClient;

/**
 * Two tenants. The tests act as users of tenant A and must never see or change anything in tenant
 * B. Everything in B carries a unique marker, so a leak shows up as the marker in a response.
 */
final class Scenario
{
    public string $marker;

    public Team $tenantA;

    public User $adminA;

    /** @var array<string, User> role => user in tenant A */
    public array $membersA = [];

    /** A client tenant of A: A is a partner, so the partner advisor in A has legitimate cross-tenant access. */
    public Team $clientOfA;

    public User $platformAdmin;

    public Team $tenantB;

    public User $ownerB;

    public User $memberB;

    public TeamInvitation $invitationB;

    public PersonalAccessToken $tokenB;

    public static function create(): self
    {
        $s = new self;
        $s->marker = 'ISOLATION-B-'.Str::upper(Str::random(12));

        $s->adminA = User::factory()->withPersonalTeam()->create();
        $s->tenantA = Team::factory()->tenant()->create(['user_id' => $s->adminA->id, 'name' => 'Tenant A']);
        $s->adminA->switchTeam($s->tenantA);

        $s->clientOfA = Team::factory()->clientOf($s->tenantA)->create(['name' => 'Client of A']);
        $s->platformAdmin = User::factory()->platformAdmin()->withPersonalTeam()->withTwoFactor()->create();

        foreach (['admin', 'partner_advisor', 'editor', 'reader'] as $role) {
            // Partner advisors must have two-factor authentication to reach anything (T07).
            $member = User::factory()->withPersonalTeam()->withTwoFactor($role === 'partner_advisor')->create();
            $s->tenantA->users()->attach($member, ['role' => $role]);
            $member->switchTeam($s->tenantA);
            $s->membersA[$role] = $member->fresh();
        }

        $s->ownerB = User::factory()->withPersonalTeam()->create([
            'name' => "{$s->marker} owner",
            'email' => strtolower($s->marker).'-owner@example.test',
        ]);
        $s->tenantB = Team::factory()->tenant()->create(['user_id' => $s->ownerB->id, 'name' => "{$s->marker} tenant"]);
        $s->memberB = User::factory()->create([
            'name' => "{$s->marker} member",
            'email' => strtolower($s->marker).'-member@example.test',
        ]);
        $s->tenantB->users()->attach($s->memberB, ['role' => 'editor']);
        // Invited to B and an existing user, so accepting the invitation would really change B.
        $invited = User::factory()->withPersonalTeam()->create([
            'email' => strtolower($s->marker).'-invited@example.test',
        ]);
        $s->invitationB = $s->tenantB->teamInvitations()->create([
            'email' => $invited->email,
            'role' => 'reader',
        ]);
        $s->tokenB = $s->ownerB->tokens()->create([
            'name' => "{$s->marker} token",
            'token' => hash('sha256', Str::random(40)),
            'abilities' => ['read'],
        ]);

        // A Claude connection in B (ADR 0016), made up front so it is part of B's snapshot.
        $s->mcpConnectionOf($s->ownerB, $s->tenantB);

        $s->adminA = $s->adminA->fresh();

        return $s;
    }

    /**
     * A fingerprint of everything in tenant B and its users, to prove a request changed nothing.
     *
     * @return array<string, mixed>
     */
    public function snapshotOfB(): array
    {
        $tenant = $this->tenantB->fresh();

        return [
            'tenant' => $tenant?->only(['id', 'name', 'user_id', 'partner_team_id', 'plan_id', 'status', 'byok_enabled']),
            'members' => $tenant?->users()->orderBy('users.id')->get()->map(fn (User $u) => [$u->id, $u->membership->role])->all(),
            'invitations' => $tenant?->teamInvitations()->orderBy('id')->get(['id', 'email', 'role'])->toArray(),
            'owner' => $this->ownerB->fresh()?->only(['id', 'name', 'email', 'current_team_id']),
            'member' => $this->memberB->fresh()?->only(['id', 'name', 'email', 'current_team_id']),
            'tokens' => PersonalAccessToken::query()->where('tokenable_id', $this->ownerB->id)->orderBy('id')->get(['id', 'name', 'abilities'])->toArray(),
            'mcp_connections' => McpConnection::query()->where('user_id', $this->ownerB->id)->orderBy('id')->get(['id', 'revoked_at', 'access_token_id'])->toArray(),
        ];
    }

    /**
     * An API token of the signed-in owner of A, for the positive control of token routes.
     */
    public function tokenOfAdminA(): PersonalAccessToken
    {
        return $this->adminA->tokens()->create([
            'name' => 'Own token',
            'token' => hash('sha256', Str::random(40)),
            'abilities' => ['read'],
        ]);
    }

    /**
     * An invitation for the owner of A to A's client tenant, for the positive control of accepting.
     */
    public function invitationForAdminA(): TeamInvitation
    {
        return $this->clientOfA->teamInvitations()->firstOrCreate(['email' => $this->adminA->email], ['role' => 'reader']);
    }

    /**
     * An invitation in tenant A, for routes that act on an invitation in the user's own tenant.
     */
    public function invitationInA(): TeamInvitation
    {
        return $this->tenantA->teamInvitations()->create(['email' => 'invited-a@example.test', 'role' => 'reader']);
    }

    public function brandIn(Team $tenant): Brand
    {
        return app(TenantContext::class)->run($tenant->id, fn () => Brand::query()->firstOrCreate(['slug' => 'bot-logs-isolation'], [
            'name' => $tenant->is($this->tenantB) ? $this->marker : 'Tenant A Brand',
            'primary_domain' => 'site.example.test',
        ]));
    }

    /**
     * A Claude connection of the user to the tenant (ADR 0016), for the connection routes. Made once per user and
     * tenant, so building a request does not change the tenant.
     */
    public function mcpConnectionOf(User $user, Team $tenant): McpConnection
    {
        $existing = McpConnection::query()->where('user_id', $user->id)->where('team_id', $tenant->id)->whereNull('revoked_at')->first();
        if ($existing !== null) {
            return $existing;
        }
        McpTestClient::connect($user, $tenant);

        return McpConnection::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    public function userWithRole(string $role): User
    {
        return match ($role) {
            'owner' => $this->adminA,
            'platform_admin' => $this->platformAdmin,
            default => $this->membersA[$role],
        };
    }
}
