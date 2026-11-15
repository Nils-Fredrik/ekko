<?php

use App\Models\Activity;
use App\Models\CompetitorAlias;
use App\Models\CompetitorDomain;
use App\Models\Integration;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\AuditLog;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Facades\Socialite;

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

test('removing someone outside the tenant, or removing twice, is refused and recorded once', function () {
    $admin = User::factory()->create();
    $this->tenant->users()->attach($admin, ['role' => 'admin']);
    $member = User::factory()->create();
    $this->tenant->users()->attach($member, ['role' => 'reader']);
    $admin->switchTeam($this->tenant);
    $stranger = User::factory()->create();

    $this->actingAs($admin)->delete("/teams/{$this->tenant->id}/members/{$stranger->id}")->assertNotFound();
    $this->actingAs($admin)->delete("/teams/{$this->tenant->id}/members/{$member->id}");
    $this->actingAs($admin)->delete("/teams/{$this->tenant->id}/members/{$member->id}")->assertNotFound();

    $entries = auditEntries('member.removed');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->subject_id)->toBe($member->id);
});

test('acting as a tenant does not start when the revision log cannot be written', function () {
    $admin = User::factory()->platformAdmin()->withPersonalTeam()->create();
    // Rolled back with the test transaction.
    DB::statement('ALTER TABLE activity_log RENAME TO activity_log_unavailable');

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
        ->post("/admin/acting-as/{$this->tenant->id}", ['reason' => 'Support case 4711'])
        ->assertServerError()
        ->assertSessionMissing('acting_as');
});

test('a partner advisor changing a role in a client tenant is recorded in the client tenant', function () {
    $partner = Team::factory()->tenant()->create();
    $client = Team::factory()->tenant()->create(['partner_team_id' => $partner->id]);
    // Partner advisors need two-factor authentication (ADR 0008).
    $advisor = User::factory()->withTwoFactor()->create();
    $partner->users()->attach($advisor, ['role' => 'partner_advisor']);
    $advisor->switchTeam($partner);
    $member = User::factory()->create();
    $client->users()->attach($member, ['role' => 'reader']);

    $this->actingAs($advisor)->put("/teams/{$client->id}/members/{$member->id}", ['role' => 'editor'])
        ->assertSessionHasNoErrors();

    $entries = auditEntries('member.role_changed');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->tenant_id)->toBe($client->id)
        ->and($entries[0]->causer_id)->toBe($advisor->id);
});

test('an invitation accepted through Google login is recorded as done by the new member', function () {
    config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret']);
    $this->tenant->teamInvitations()->create(['email' => 'newcomer@example.test', 'role' => 'editor']);
    $social = (new Laravel\Socialite\Two\User)->map(['id' => 'google-1', 'name' => 'Newcomer', 'email' => 'newcomer@example.test']);
    $social->user = ['email_verified' => true];
    Socialite::fake('google', $social);

    $this->get('/auth/google/callback');

    $newcomer = User::query()->where('email', 'newcomer@example.test')->firstOrFail();
    $entries = auditEntries('member.added');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->tenant_id)->toBe($this->tenant->id)
        ->and($entries[0]->causer_id)->toBe($newcomer->id)
        ->and(json_decode($entries[0]->properties, true))->toBe(['role' => 'editor']);
});

test('switching from one tenant to another records the stop and the start, in that order', function () {
    $admin = User::factory()->platformAdmin()->withPersonalTeam()->create();
    $second = Team::factory()->tenant()->create();
    $session = ['auth.password_confirmed_at' => time()];

    $this->actingAs($admin)->withSession($session)->post("/admin/acting-as/{$this->tenant->id}", ['reason' => 'Support case 4711']);
    $this->actingAs($admin)->withSession($session)->post("/admin/acting-as/{$second->id}", ['reason' => 'Support case 4712']);

    $events = array_map(fn ($entry) => [$entry->event, $entry->tenant_id], auditEntries());

    expect($events)->toBe([
        ['acting_as.started', $this->tenant->id],
        ['acting_as.stopped', $this->tenant->id],
        ['acting_as.started', $second->id],
    ]);
});

test('switching tenant keeps the current acting-as when the revision log cannot be written', function () {
    $admin = User::factory()->platformAdmin()->withPersonalTeam()->create();
    $second = Team::factory()->tenant()->create();
    $session = ['auth.password_confirmed_at' => time()];

    $this->actingAs($admin)->withSession($session)->post("/admin/acting-as/{$this->tenant->id}", ['reason' => 'Support case 4711']);
    Log::spy();
    // Only the entry for the second tenant fails, so the stop entry for the first could be written.
    // Rolled back with the test transaction.
    DB::unprepared(<<<SQL
        CREATE FUNCTION test_refuse_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$
        BEGIN
            IF NEW.tenant_id = {$second->id} THEN RAISE EXCEPTION 'revision log unavailable'; END IF;
            RETURN NEW;
        END \$\$;
        CREATE TRIGGER test_refuse_audit BEFORE INSERT ON activity_log FOR EACH ROW EXECUTE FUNCTION test_refuse_audit();
    SQL);

    $this->actingAs($admin)->withSession($session)->post("/admin/acting-as/{$second->id}", ['reason' => 'Support case 4712'])
        ->assertServerError()
        ->assertSessionHas('acting_as.tenant_id', $this->tenant->id);

    // The stop entry for the first tenant is rolled back with the request, in both logs.
    expect(auditEntries('acting_as.stopped'))->toBe([]);
    Log::shouldNotHaveReceived('info', ['acting_as.stopped', Mockery::any()]);
});

test('competitor aliases and domains are recorded', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $alias = CompetitorAlias::factory()->create(['alias' => 'Rival Bank']);
    $domain = CompetitorDomain::factory()->create(['domain' => 'rival.example']);

    $alias->update(['match_mode' => 'case_insensitive']);
    $domain->delete();

    $subjects = array_map(fn ($entry) => [$entry->subject_type, $entry->event], auditEntries());

    expect($subjects)->toContain([CompetitorAlias::class, 'created'])
        ->toContain([CompetitorAlias::class, 'updated'])
        ->toContain([CompetitorDomain::class, 'created'])
        ->toContain([CompetitorDomain::class, 'deleted']);
});

test('a member added without a role is recorded with no role, not an empty one', function () {
    $admin = User::factory()->platformAdmin()->create();
    $member = User::factory()->create();

    app(AuditLog::class)->memberAdded($admin, $this->tenant, $member, null);

    expect(json_decode(auditEntries('member.added')[0]->properties, true))->toBe(['role' => null]);
});
