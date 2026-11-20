<?php

// T49, docs/spec.md 11.5: the pitch page lists the pitch projects of the partner the advisor is signed into, with
// status and progress read inside each pitch, and opens a pitch in the wizard or on its report.

use App\Models\Brand;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Run;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z'));
    $this->partner = Team::factory()->tenant()->create(['plan_id' => Plan::factory()->create()->id]);
    $this->advisor = User::factory()->withTwoFactor()->create();
    $this->partner->users()->attach($this->advisor, ['role' => 'partner_advisor']);
    $this->advisor->switchTeam($this->partner);
});

function pitchOf(Team $partner, string $name, array $attributes = []): Team
{
    return Team::factory()->clientOf($partner)->create(['name' => $name, 'kind' => 'pitch',
        'created_at' => now()->subDays(2), 'pitch_expires_at' => now()->addDays(5), ...$attributes]);
}

it('lists the pitches of the current partner with status and progress, never another partner\'s or an ordinary client', function () {
    $running = pitchOf($this->partner, 'Prospekt AS');
    app(TenantContext::class)->run($running->id, function () {
        $brand = Brand::factory()->create(['name' => 'Prospekt']);
        $market = Market::factory()->create(['brand_id' => $brand->id]);
        $prompt = Prompt::factory()->create(['market_id' => $market->id]);
        Response::factory()->create(['run_id' => Run::factory()->create(['prompt_id' => $prompt->id])->id, 'collected_at' => '2026-11-19T08:00:00Z']);
    });
    pitchOf($this->partner, 'Utløpt AS', ['created_at' => now()->subDays(9), 'pitch_expires_at' => now()->subDays(2)]);
    pitchOf($this->partner, 'Konvertert AS', ['kind' => 'customer', 'pitch_converted_at' => now()->subDay()]);
    Team::factory()->clientOf($this->partner)->create(['name' => 'Vanlig kunde']);
    $otherPartner = Team::factory()->tenant()->create();
    pitchOf($otherPartner, 'Annen partner AS');

    $this->actingAs($this->advisor)->get(route('pitches.index'))->assertOk()
        ->assertDontSee('Annen partner AS')
        ->assertInertia(fn ($page) => $page->component('Pitch/Index')
            ->where('limit', 3)->where('active', 1)->has('pitches', 3)
            ->where('pitches.0.name', 'Prospekt AS')->where('pitches.0.status', 'active')->where('pitches.0.day', 3)
            ->where('pitches.0.brand.name', 'Prospekt')->where('pitches.0.prompts', 1)->where('pitches.0.answers', 1)
            ->where('pitches.0.last_answer_at', '2026-11-19T08:00:00+00:00')
            ->where('pitches.1.name', 'Utløpt AS')->where('pitches.1.status', 'expired')->where('pitches.1.brand', null)->where('pitches.1.day', 7)
            ->where('pitches.2.name', 'Konvertert AS')->where('pitches.2.status', 'converted'));
});

it('opens a pitch in the wizard until the brand is set up, then on the pitch report', function () {
    $pitch = pitchOf($this->partner, 'Prospekt AS');

    $this->actingAs($this->advisor)->post(route('pitches.open', $pitch))->assertRedirect(route('onboarding.wizard'));
    expect($this->advisor->fresh()->current_team_id)->toBe($pitch->id);

    $brand = app(TenantContext::class)->run($pitch->id, fn () => Brand::factory()->create());
    $this->advisor->switchTeam($this->partner);
    $this->post(route('pitches.open', $pitch))->assertRedirect(route('pitches.report', $brand));
});

it('refuses to open an ordinary client, another partner\'s pitch, or for someone who does not advise the partner', function () {
    $client = Team::factory()->clientOf($this->partner)->create();
    $foreign = pitchOf(Team::factory()->tenant()->create(), 'Annen AS');
    $pitch = pitchOf($this->partner, 'Prospekt AS');

    $this->actingAs($this->advisor)->post(route('pitches.open', $client))->assertNotFound();
    $this->post(route('pitches.open', $foreign))->assertForbidden();
    $owner = $this->partner->owner;
    $owner->switchTeam($this->partner);
    $this->actingAs($owner)->post(route('pitches.open', $pitch))->assertForbidden();
    $this->get(route('pitches.index'))->assertForbidden();
    expect($this->advisor->fresh()->current_team_id)->toBe($this->partner->id);
});
