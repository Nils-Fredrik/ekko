<?php

use App\Models\Activity;
use App\Models\Integration;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\AuditLog;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

// Board task:44 and docs/spec.md 16: the revision log (spatie/laravel-activitylog) records acting
// as a tenant, role changes and changes to prompts, competitors, alert rules and integrations. Each
// entry has the tenant_id of the tenant it concerns and lies under RLS.

/**
 * @return list<stdClass>
 */
function auditEntries(?string $event = null): array
{
    return DB::table('activity_log')
        ->where('log_name', AuditLog::LOG_NAME)
        ->when($event !== null, fn ($query) => $query->where('event', $event))
        ->orderBy('id')
        ->get()
        ->all();
}

beforeEach(function () {
    $this->tenant = Team::factory()->tenant()->create();
});

test('acting as a tenant is recorded in that tenant, with the admin and the reason', function () {
    $admin = User::factory()->platformAdmin()->withPersonalTeam()->create();

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
        ->post("/admin/acting-as/{$this->tenant->id}", ['reason' => 'Support case 4711']);
    $this->delete('/admin/acting-as');

    $started = auditEntries('acting_as.started');
    $stopped = auditEntries('acting_as.stopped');

    expect($started)->toHaveCount(1)
        ->and($started[0]->tenant_id)->toBe($this->tenant->id)
        ->and($started[0]->causer_id)->toBe($admin->id)
        ->and(json_decode($started[0]->properties, true))->toBe(['reason' => 'Support case 4711'])
        ->and($stopped)->toHaveCount(1)
        ->and($stopped[0]->tenant_id)->toBe($this->tenant->id);
});

test('a role change is recorded with the old and the new role', function () {
    $admin = User::factory()->create();
    $this->tenant->users()->attach($admin, ['role' => 'admin']);
    $member = User::factory()->create();
    $this->tenant->users()->attach($member, ['role' => 'reader']);
    $admin->switchTeam($this->tenant);

    $this->actingAs($admin)->put("/teams/{$this->tenant->id}/members/{$member->id}", ['role' => 'editor'])
        ->assertSessionHasNoErrors();

    $entries = auditEntries('member.role_changed');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->tenant_id)->toBe($this->tenant->id)
        ->and($entries[0]->causer_id)->toBe($admin->id)
        ->and($entries[0]->subject_id)->toBe($member->id)
        ->and(json_decode($entries[0]->properties, true))->toEqual(['from' => 'reader', 'to' => 'editor']);
});

test('removing a member is recorded with the role the member had', function () {
    $admin = User::factory()->create();
    $this->tenant->users()->attach($admin, ['role' => 'admin']);
    $member = User::factory()->create();
    $this->tenant->users()->attach($member, ['role' => 'editor']);
    $admin->switchTeam($this->tenant);

    $this->actingAs($admin)->delete("/teams/{$this->tenant->id}/members/{$member->id}");

    $entries = auditEntries('member.removed');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->causer_id)->toBe($admin->id)
        ->and($entries[0]->subject_id)->toBe($member->id)
        ->and(json_decode($entries[0]->properties, true))->toBe(['role' => 'editor']);
});

test('an accepted invitation is recorded as done by the new member', function () {
    $member = User::factory()->create();
    $invitation = $this->tenant->teamInvitations()->create(['email' => $member->email, 'role' => 'reader']);

    $this->actingAs($member)->get(URL::signedRoute('team-invitations.accept', ['invitation' => $invitation->id]));

    $entries = auditEntries('member.added');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->tenant_id)->toBe($this->tenant->id)
        ->and($entries[0]->causer_id)->toBe($member->id)
        ->and(json_decode($entries[0]->properties, true))->toBe(['role' => 'reader']);
});

test('a prompt change is recorded without the prompt text', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $prompt = Prompt::factory()->create(['text' => 'Which bank is best for students?']);

    $prompt->update(['text' => 'Which bank is cheapest?', 'priority' => 'core']);

    $entries = auditEntries('updated');
    $raw = DB::table('activity_log')->where('log_name', AuditLog::LOG_NAME)->pluck('attribute_changes')->implode(' ');

    expect(auditEntries('created'))->not->toBeEmpty()
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]->tenant_id)->toBe($this->tenant->id)
        ->and($entries[0]->subject_type)->toBe(Prompt::class)
        ->and(json_decode($entries[0]->attribute_changes, true)['attributes'])->toMatchArray(['text' => '[changed]', 'priority' => 'core'])
        ->and($raw)->not->toContain('bank');
});

test('an integration change is recorded without the credentials', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $integration = Integration::factory()->create();

    $integration->update(['config' => ['service_account_key' => 'secret-key-value']]);

    $entries = auditEntries('updated');
    $raw = DB::table('activity_log')->where('log_name', AuditLog::LOG_NAME)->pluck('attribute_changes')->implode(' ');

    expect($entries)->toHaveCount(1)
        ->and(json_decode($entries[0]->attribute_changes, true)['attributes'])->toBe(['config' => '[changed]'])
        ->and($raw)->not->toContain('secret-key-value');
});

test('the revision log lies under RLS: another tenant sees none of it', function () {
    $admin = User::factory()->platformAdmin()->create();
    app(AuditLog::class)->actingAsStarted($admin, $this->tenant, 'Support case 4711');
    $other = Team::factory()->tenant()->create();
    $rls = app(TenantRls::class);

    expect($rls->run([$this->tenant->id], fn () => DB::table('activity_log')->count()))->toBe(1)
        ->and($rls->run([$other->id], fn () => DB::table('activity_log')->count()))->toBe(0)
        ->and(fn () => $rls->run([$other->id], fn () => DB::table('activity_log')->insert([
            'tenant_id' => $this->tenant->id, 'description' => 'forged', 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(QueryException::class);
});

test('the audit write does not change the tenant context of the request', function () {
    $other = Team::factory()->tenant()->create();
    app(TenantContext::class)->set($other->id);

    app(AuditLog::class)->actingAsStarted(User::factory()->platformAdmin()->create(), $this->tenant, 'Support case 4711');

    expect(app(TenantContext::class)->id())->toBe($other->id)
        ->and(auditEntries('acting_as.started')[0]->tenant_id)->toBe($this->tenant->id);
});

test('a role change for someone outside the tenant is refused and not recorded', function () {
    $admin = User::factory()->create();
    $this->tenant->users()->attach($admin, ['role' => 'admin']);
    $admin->switchTeam($this->tenant);
    $stranger = User::factory()->create();

    $this->actingAs($admin)->put("/teams/{$this->tenant->id}/members/{$stranger->id}", ['role' => 'editor'])
        ->assertNotFound();

    expect(auditEntries('member.role_changed'))->toBe([])
        ->and($this->tenant->fresh()->hasUser($stranger))->toBeFalse();
});

test('setting the role a member already has records nothing', function () {
    $admin = User::factory()->create();
    $this->tenant->users()->attach($admin, ['role' => 'admin']);
    $member = User::factory()->create();
    $this->tenant->users()->attach($member, ['role' => 'editor']);
    $admin->switchTeam($this->tenant);

    $this->actingAs($admin)->put("/teams/{$this->tenant->id}/members/{$member->id}", ['role' => 'editor'])
        ->assertSessionHasNoErrors();

    expect(auditEntries('member.role_changed'))->toBe([]);
});

test('masking fails closed when the changes have an unknown shape', function () {
    $activityWith = fn (mixed $changes) => new class($changes) extends Activity
    {
        public function __construct(private mixed $fakeChanges = null)
        {
            parent::__construct();
        }

        public function getAttribute($key): mixed
        {
            return $key === 'attribute_changes' ? $this->fakeChanges : parent::getAttribute($key);
        }
    };

    // A plain array is masked like the collection the cast gives today.
    $array = $activityWith(['attributes' => ['text' => 'secret text', 'priority' => 'core']]);
    (new Prompt)->beforeActivityLogged($array, 'updated');

    expect($array->getAttributes()['attribute_changes'])->toContain('[changed]')->not->toContain('secret text');

    // Anything else is refused rather than written unmasked.
    expect(fn () => (new Prompt)->beforeActivityLogged($activityWith('secret text'), 'updated'))
        ->toThrow(LogicException::class);
});
