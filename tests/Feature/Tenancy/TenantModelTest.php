<?php

use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\InvalidPartnerException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a new tenant gets the default values from the spec', function () {
    $team = Team::factory()->tenant()->create()->refresh();

    expect($team->status)->toBe(TenantStatus::Active)
        ->and($team->isActive())->toBeTrue()
        ->and($team->default_locale)->toBe('nb')
        ->and($team->timezone)->toBe('Europe/Oslo')
        ->and($team->byok_enabled)->toBeFalse()
        ->and($team->partner_team_id)->toBeNull()
        ->and($team->plan_id)->toBeNull()
        ->and($team->branding)->toBeNull();
});

test('admin-controlled tenant columns are not mass assignable', function () {
    $partner = Team::factory()->tenant()->create();
    $plan = Plan::factory()->create();

    $team = Team::factory()->tenant()->create();
    $team->update([
        'partner_team_id' => $partner->id,
        'plan_id' => $plan->id,
        'status' => 'closed',
        'byok_enabled' => true,
    ]);

    expect($team->refresh()->partner_team_id)->toBeNull()
        ->and($team->plan_id)->toBeNull()
        ->and($team->status)->toBe(TenantStatus::Active)
        ->and($team->byok_enabled)->toBeFalse();
});

test('the Slack webhook is encrypted in the database and never serialized', function () {
    $team = Team::factory()->tenant()->create();
    $team->forceFill(['slack_webhook_url' => 'https://hooks.slack.test/abc'])->save();

    $raw = DB::table('teams')->where('id', $team->id)->value('slack_webhook_url');

    expect($raw)->not->toBe('https://hooks.slack.test/abc')
        ->and($team->refresh()->slack_webhook_url)->toBe('https://hooks.slack.test/abc')
        ->and($team->toArray())->not->toHaveKey('slack_webhook_url');
});

test('branding is stored as JSON', function () {
    $team = Team::factory()->tenant()->create();
    $team->forceFill(['branding' => ['logo' => 'logos/a.svg', 'primary_color' => '#e84a38']])->save();

    expect($team->refresh()->branding)->toBe(['logo' => 'logos/a.svg', 'primary_color' => '#e84a38']);
});

test('the status only accepts active, paused and closed', function () {
    $team = Team::factory()->tenant()->create();

    expect(fn () => DB::table('teams')->where('id', $team->id)->update(['status' => 'deleted']))
        ->toThrow(QueryException::class);
});

test('a partner tenant has client tenants', function () {
    $partner = Team::factory()->tenant()->create();
    $client = Team::factory()->clientOf($partner)->create();

    expect($client->partner->is($partner))->toBeTrue()
        ->and($partner->clients->pluck('id')->all())->toBe([$client->id])
        ->and($partner->isPartner())->toBeTrue()
        ->and($client->isPartner())->toBeFalse();
});

test('a partner cannot itself have a partner', function () {
    $partner = Team::factory()->tenant()->create();
    $client = Team::factory()->clientOf($partner)->create();

    Team::factory()->clientOf($client)->create();
})->throws(InvalidPartnerException::class, 'cannot itself have a partner');

test('a tenant with clients cannot get a partner', function () {
    $partner = Team::factory()->tenant()->create();
    Team::factory()->clientOf($partner)->create();
    $other = Team::factory()->tenant()->create();

    $partner->forceFill(['partner_team_id' => $other->id])->save();
})->throws(InvalidPartnerException::class, 'cannot get a partner');

test('a tenant cannot be its own partner', function () {
    $team = Team::factory()->tenant()->create();

    $team->forceFill(['partner_team_id' => $team->id])->save();
})->throws(InvalidPartnerException::class, 'own partner');

test('the database also refuses a tenant as its own partner', function () {
    $team = Team::factory()->tenant()->create();

    DB::table('teams')->where('id', $team->id)->update(['partner_team_id' => $team->id]);
})->throws(QueryException::class);

test('a tenant has a plan', function () {
    $plan = Plan::factory()->create(['allowed_engines' => ['chatgpt_ui', 'claude_haiku']]);
    $team = Team::factory()->tenant()->withPlan($plan)->create();

    expect($team->plan->is($plan))->toBeTrue()
        ->and($plan->teams->pluck('id')->all())->toBe([$team->id])
        ->and($team->plan->allowed_engines)->toBe(['chatgpt_ui', 'claude_haiku']);
});

test('a plan only accepts the frequencies and repetitions in the spec', function (array $attributes) {
    Plan::factory()->create($attributes);
})->with([
    'hourly' => [['max_frequency' => 'hourly']],
    '0 repetitions' => [['max_repetitions' => 0]],
    '4 repetitions' => [['max_repetitions' => 4]],
])->throws(QueryException::class);

test('platform admin is a flag that cannot be mass assigned', function () {
    $user = User::factory()->create();

    expect($user->refresh()->isPlatformAdmin())->toBeFalse();

    $user->update(['is_platform_admin' => true]);
    expect($user->refresh()->isPlatformAdmin())->toBeFalse();

    expect(User::factory()->platformAdmin()->create()->isPlatformAdmin())->toBeTrue();
});

test('a user has an optional locale', function () {
    $user = User::factory()->create();

    expect($user->refresh()->locale)->toBeNull();

    $user->forceFill(['locale' => 'sv'])->save();
    expect($user->refresh()->locale)->toBe('sv');
});
