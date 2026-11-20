<?php

use App\Models\Action;
use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use App\Pitch\PitchProjects;
use App\Tenancy\Rls\TenantRls;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Metrics\MetricsWorld;

test('pitch creation and conversion are audited in the pitch tenant with the actual actors', function () {
    $partner = Team::factory()->tenant()->create();
    $customerPlan = Plan::factory()->create();
    $partner->forceFill(['plan_id' => $customerPlan->id])->save();
    $advisor = User::factory()->create();
    $partner->users()->attach($advisor, ['role' => 'partner_advisor']);
    $admin = User::factory()->platformAdmin()->create();
    $projects = app(PitchProjects::class);
    $at = CarbonImmutable::now('UTC');
    $pitch = $projects->create($advisor, $partner, 'Prospect', $at);
    $pitchPlanId = $pitch->plan_id;
    $projects->convert($admin, $pitch, $at->addMinute());

    $rows = app(TenantContext::class)->run($pitch->id, fn () => app(TenantRls::class)->run([$pitch->id], fn () => DB::table('activity_log')
        ->where('log_name', 'audit')->whereIn('event', ['tenant.created', 'pitch.converted'])->orderBy('id')->get()));
    expect($rows)->toHaveCount(2)->and($rows[0]->event)->toBe('tenant.created')->and($rows[0]->causer_id)->toBe($advisor->id)
        ->and($rows[1]->event)->toBe('pitch.converted')->and($rows[1]->causer_id)->toBe($admin->id)
        ->and(json_decode($rows[1]->properties, true))->toEqual([
            'from' => ['kind' => 'pitch', 'plan_id' => $pitchPlanId],
            'to' => ['kind' => 'customer', 'plan_id' => $customerPlan->id],
            'partner_team_id' => $partner->id,
        ]);
    $other = Team::factory()->tenant()->create();
    expect(app(TenantRls::class)->run([$other->id], fn () => DB::table('activity_log')->where('tenant_id', $pitch->id)->count()))->toBe(0);
});

test('the pitch report uses its historical date range and the three highest ranked open actions', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00 UTC'));
    $world = MetricsWorld::create('UTC');
    $world->tenant->forceFill(['kind' => 'pitch', 'created_at' => now()->subDays(3), 'pitch_expires_at' => now()->subDay()])->save();
    foreach (range(1, 10) as $index) {
        $answer = $world->answer($world->p1, '2026-11-18T10:00:00Z', [
            'brand' => $index <= 5 ? [] : null,
            'competitors' => $index <= 3 ? [$world->c1->id => 1] : [],
            'citations' => $index <= 4 ? [['example.test', 'third_party']] : [],
        ]);
        $answer->run->forceFill(['created_at' => '2026-11-18T09:00:00Z'])->save();
    }
    $answer = $world->answer($world->p1, '2026-11-20T10:00:00Z', ['brand' => []]);
    $answer->run->forceFill(['created_at' => '2026-11-20T09:00:00Z'])->save();
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

    $answer = $world->answer($world->p1, '2026-11-18T18:00:00Z', ['brand' => []]);
    $answer->run->forceFill(['created_at' => '2026-11-18T17:00:00Z'])->save();
    $answer = $world->answer($world->p1, '2026-11-19T10:00:00Z', ['brand' => []]);
    $answer->run->forceFill(['created_at' => '2026-11-19T09:00:00Z'])->save();
    $world->tenant->forceFill(['kind' => 'customer', 'pitch_converted_at' => now()->subDays(2)])->save();
    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('to', '2026-11-18')->where('visibility.value', 0.5)->where('visibility.n', 10));
    $otherTenant = Team::factory()->tenant()->create();
    $otherTenant->owner->switchTeam($otherTenant);
    $this->actingAs($otherTenant->owner)->get($url)->assertNotFound();
});

test('a converted pitch report separates answers on the same local day at the conversion time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z'));
    $world = MetricsWorld::create('Europe/Oslo');
    $world->tenant->forceFill([
        'kind' => 'customer',
        'created_at' => CarbonImmutable::parse('2026-11-18T22:30:00Z'),
        'pitch_expires_at' => CarbonImmutable::parse('2026-11-25T22:30:00Z'),
        'pitch_converted_at' => CarbonImmutable::parse('2026-11-18T23:30:00Z'),
    ])->save();
    $before = $world->answer($world->p1, '2026-11-18T23:29:00Z', ['brand' => []]);
    $before->run->forceFill(['created_at' => '2026-11-18T23:28:00Z'])->save();
    $after = $world->answer($world->p1, '2026-11-18T23:31:00Z');
    $after->run->forceFill(['created_at' => '2026-11-18T23:30:30Z'])->save();
    // This pitch run returned after conversion, but was created before it.
    $late = $world->answer($world->p1, '2026-11-18T23:32:00Z', ['brand' => []]);
    $late->run->forceFill(['created_at' => '2026-11-18T23:29:00Z'])->save();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);

    $this->actingAs($owner)->get(route('pitches.report', $world->brand))->assertOk()->assertInertia(fn ($page) => $page
        ->where('from', '2026-11-18')->where('to', '2026-11-19')
        ->where('visibility.value', 1)->where('visibility.n', 2));
});

test('a pitch PDF is generated from scoped report data and saved privately for download', function () {
    $world = MetricsWorld::create('UTC');
    $world->tenant->forceFill(['kind' => 'pitch', 'created_at' => now()->subMinute(), 'pitch_expires_at' => now()->addDays(7)])->save();
    $answer = $world->answer($world->p1, now()->subSecond()->toIso8601String());
    $answer->forceFill(['llm_failed' => true])->save();
    $answer->run->forceFill(['created_at' => now()->subSecond()])->save();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    $disk = config()->string('ekko.reports.disk');
    Storage::fake($disk);
    $pdf = Pdf::fake();

    $this->actingAs($owner)->get(route('pitches.report', $world->brand))->assertOk()
        ->assertInertia(fn ($page) => $page->where('reduced_quality', 1));

    $response = $this->actingAs($owner)->get(route('pitches.report.pdf', $world->brand))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($response->streamedContent())->toStartWith('%PDF');
    $pdf->assertViewIs('reports.pdf');
    $pdf->assertViewHas('short', true);
    $pdf->assertViewHas('brand', 'Fjordkaffe');
    $pdf->assertViewHas('reduced_quality', 1);
    $pdf->assertSee('id="report-cover"', false);
    $pdf->assertDontSee('id="report-engines"', false);
    $pdf->assertSee(__('pitch.reduced_quality', ['n' => 1]));
    $files = Storage::disk($disk)->files('reports/'.$world->tenant->id);
    expect($files)->toBe(['reports/'.$world->tenant->id.'/pitch-'.$world->brand->id.'.pdf']);
    expect(Storage::disk($disk)->getVisibility($files[0]))->toBe('private');
    $this->get(route('pitches.report.pdf', $world->brand))->assertOk()->streamedContent();
    expect(Storage::disk($disk)->files('reports/'.$world->tenant->id))->toBe($files);

    $other = Team::factory()->tenant()->create();
    $other->owner->switchTeam($other);
    $this->actingAs($other->owner)->get(route('pitches.report.pdf', $world->brand))->assertNotFound();
});

test('the pitch PDF renders a real PDF document', function () {
    $world = MetricsWorld::create('UTC');
    $world->tenant->forceFill(['kind' => 'pitch', 'pitch_expires_at' => now()->addDays(7)])->save();
    $owner = $world->tenant->owner;
    $owner->switchTeam($world->tenant);
    Storage::fake(config()->string('ekko.reports.disk'));

    $response = $this->actingAs($owner)->get(route('pitches.report.pdf', $world->brand))->assertOk();
    $document = $response->streamedContent();
    expect($document)->toStartWith('%PDF')->and(strlen($document))->toBeGreaterThan(1000);
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
