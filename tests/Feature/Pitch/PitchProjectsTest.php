<?php

use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use App\Pitch\PitchProjects;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
