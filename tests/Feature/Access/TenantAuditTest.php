<?php

use App\Models\Team;
use App\Models\User;
use App\Tenancy\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

// T39-F6, docs/spec.md 16: invitations, tenant creation, renaming and closing, and API tokens are in the
// revision log of the tenant they concern, without e-mail addresses or token values.

/**
 * @return array<string, mixed>
 */
function tenantAudit(string $event, int $tenantId): array
{
    $rows = DB::table('activity_log')->where('log_name', AuditLog::LOG_NAME)->where('event', $event)->where('tenant_id', $tenantId)->get();
    expect($rows)->toHaveCount(1);

    // jsonb keeps its own key order, so callers compare with toEqual.
    return ['causer_id' => $rows[0]->causer_id, ...json_decode($rows[0]->properties, true)];
}

test('sending and cancelling an invitation are logged with the role and who did it', function () {
    Mail::fake();
    $admin = User::factory()->withPersonalTeam()->create();
    $tenant = $admin->currentTeam;

    $this->actingAs($admin)->post("/teams/{$tenant->id}/members", ['email' => 'Invited@Example.test', 'role' => 'editor']);
    $invitation = $tenant->teamInvitations()->firstOrFail();
    $this->delete("/team-invitations/{$invitation->id}");

    expect(tenantAudit('invitation.sent', $tenant->id))->toEqual(['causer_id' => $admin->id, 'invitation_id' => $invitation->id, 'role' => 'editor'])
        ->and(tenantAudit('invitation.cancelled', $tenant->id))->toEqual(['causer_id' => $admin->id, 'invitation_id' => $invitation->id, 'role' => 'editor']);
    expect(DB::table('activity_log')->pluck('properties')->implode(' '))->not->toContain('example.test');
});

test('creating, renaming and closing a tenant are logged', function () {
    $admin = User::factory()->platformAdmin()->withPersonalTeam()->create();

    $this->actingAs($admin)->post('/teams', ['name' => 'Fjordkaffe AS']);
    $tenant = $admin->fresh()->ownedTeams()->latest('id')->firstOrFail();
    $this->put("/teams/{$tenant->id}", ['name' => 'Fjordkaffe Norge AS']);
    $this->withSession(['auth.password_confirmed_at' => time()])->delete("/teams/{$tenant->id}");

    expect(tenantAudit('tenant.created', $tenant->id))->toEqual(['causer_id' => $admin->id, 'name' => 'Fjordkaffe AS'])
        ->and(tenantAudit('tenant.renamed', $tenant->id))->toEqual(['causer_id' => $admin->id, 'from' => 'Fjordkaffe AS', 'to' => 'Fjordkaffe Norge AS'])
        ->and(tenantAudit('tenant.closed', $tenant->id)['purge_after'])->toBe($tenant->fresh()->closed_at->addDays(Team::PURGE_AFTER_DAYS)->toIso8601String());
});

test('saving the same name logs nothing', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)->put("/teams/{$user->currentTeam->id}", ['name' => $user->currentTeam->name]);

    expect(DB::table('activity_log')->where('event', 'tenant.renamed')->count())->toBe(0);
});

test('creating, changing and deleting an API token are logged in its tenant, without the token', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $tenant = $user->currentTeam;

    $this->actingAs($user)->post('/user/api-tokens', ['name' => 'Rapporter', 'permissions' => ['read']]);
    $token = $user->fresh()->tokens()->firstOrFail();
    $this->put("/user/api-tokens/{$token->id}", ['permissions' => ['read', 'write']]);
    $this->delete("/user/api-tokens/{$token->id}");

    expect(tenantAudit('api_token.created', $tenant->id))->toEqual(['causer_id' => $user->id, 'token_id' => $token->id, 'name' => 'Rapporter', 'abilities' => ['read', "tenant:{$tenant->id}"]])
        ->and(tenantAudit('api_token.updated', $tenant->id))->toEqual(['causer_id' => $user->id, 'token_id' => $token->id, 'from' => ['read', "tenant:{$tenant->id}"], 'to' => ['read', 'write', "tenant:{$tenant->id}"]])
        ->and(tenantAudit('api_token.deleted', $tenant->id))->toEqual(['causer_id' => $user->id, 'token_id' => $token->id, 'name' => 'Rapporter']);
    expect(DB::table('activity_log')->pluck('properties')->implode(' '))->not->toContain((string) $token->token);
});
