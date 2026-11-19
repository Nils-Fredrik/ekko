<?php

use App\Models\Action;
use App\Models\AggDaily;
use App\Models\AggSourceDaily;
use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use App\Pitch\PitchProjects;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Metrics\MetricsWorld;

test('the pitch report uses its historical date range and the three highest ranked open actions', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00 UTC'));
    $world = MetricsWorld::create('UTC');
    $world->tenant->forceFill(['kind' => 'pitch', 'created_at' => now()->subDays(3), 'pitch_expires_at' => now()->subDay()])->save();
    AggDaily::factory()->create([
        'market_id' => $world->market->id, 'prompt_id' => $world->p1->id, 'engine_key' => $world->engine->key,
        'date' => '2026-11-18', 'responses' => 10, 'brand_mentioned' => 5, 'competitor_mentions' => [$world->c1->id => 3],
    ]);
    AggDaily::factory()->create([
        'market_id' => $world->market->id, 'prompt_id' => $world->p1->id, 'engine_key' => $world->engine->key,
        'date' => '2026-11-20', 'responses' => 100, 'brand_mentioned' => 100,
    ]);
    AggSourceDaily::factory()->create(['market_id' => $world->market->id, 'engine_key' => $world->engine->key, 'date' => '2026-11-18', 'domain' => 'example.test', 'citations' => 4]);
    foreach ([10, 30, 20, 40] as $score) {
        Action::factory()->for($world->brand)->create(['title' => 'Action '.$score, 'impact_score' => $score]);
    }
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $url = route('pitches.report', $world->brand);

    $this->actingAs($owner)->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->component('Pitch/Report')
        ->where('from', '2026-11-17')->where('to', '2026-11-19')
        ->where('visibility.value', 0.5)->where('visibility.n', 10)
        ->where('competitors.0.name', 'Nordlys')->where('competitors.0.visibility.value', 0.3)
        ->where('sources.0.domain', 'example.test')->where('sources.0.citations', 4)
        ->where('actions.open.0.title', 'Action 40')->where('actions.open.1.title', 'Action 30')
        ->where('actions.open.2.title', 'Action 20')->has('actions.open', 3));

    AggDaily::factory()->create([
        'market_id' => $world->market->id, 'prompt_id' => $world->p1->id, 'engine_key' => $world->engine->key,
        'date' => '2026-11-19', 'responses' => 100, 'brand_mentioned' => 100,
    ]);
    $world->tenant->forceFill(['kind' => 'customer', 'pitch_converted_at' => now()->subDays(2)])->save();
    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('to', '2026-11-18')->where('visibility.value', 0.5)->where('visibility.n', 10));
    $otherTenant = Team::factory()->tenant()->create();
    $otherTenant->owner->switchTeam($otherTenant);
    $this->actingAs($otherTenant->owner)->get($url)->assertNotFound();
});

test('partner quota counts only live pitches and conversion retains the same tenant', function () {
    $partner = Team::factory()->tenant()->create();
    $advisor = User::factory()->create();
    $partner->users()->attach($advisor, ['role' => 'partner_advisor']);
    $plan = Plan::factory()->create();
    $partner->forceFill(['plan_id' => $plan->id])->save();
    $projects = app(PitchProjects::class);
    $now = CarbonImmutable::parse('2026-11-19 12:00:00 UTC');

    $first = $projects->create($advisor, $partner, 'Prospect 1', $now);
    $projects->create($advisor, $partner, 'Prospect 2', $now);
    $projects->create($advisor, $partner, 'Prospect 3', $now);
    expect($first->mayCollectAt($now->addDays(7)->subSecond()))->toBeTrue()
        ->and($first->mayCollectAt($now->addDays(7)))->toBeFalse();
    expect(fn () => $projects->create($advisor, $partner, 'Fourth', $now))
        ->toThrow(ValidationException::class);
    $converted = $projects->convert($advisor, $first, $now->addDay());
    expect($converted->id)->toBe($first->id)->and($converted->kind)->toBe('customer')->and($converted->plan_id)->toBe($plan->id)
        ->and($converted->mayCollectAt($now->addDays(8)))->toBeTrue();
    expect($projects->create($advisor, $partner, 'Fourth', $now->addDay())->kind)->toBe('pitch');
});

test('an unrelated advisor cannot create or convert a pitch for another partner', function () {
    $partner = Team::factory()->tenant()->create();
    $other = Team::factory()->tenant()->create();
    $advisor = User::factory()->create();
    $other->users()->attach($advisor, ['role' => 'partner_advisor']);
    $projects = app(PitchProjects::class);
    $now = CarbonImmutable::now('UTC');
    expect(fn () => $projects->create($advisor, $partner, 'No', $now))->toThrow(HttpException::class);
    $admin = User::factory()->platformAdmin()->create();
    $pitch = $projects->create($admin, $partner, 'Protected pitch', $now);
    expect(fn () => $projects->convert($advisor, $pitch, $now))->toThrow(HttpException::class);
    expect($pitch->fresh()->kind)->toBe('pitch');
});
