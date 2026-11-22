<?php

use App\Models\Competitor;
use App\Models\EntityDecision;
use App\Models\User;
use App\Workspace\WorkspaceCounts;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Metrics\MetricsWorld;

// U22: Setup › Competitors and brands (docs/ux-arbeidsflate.md 5.3).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Europe/Oslo'));
    $this->w = MetricsWorld::create();
    $this->w->tenant->owner->switchTeam($this->w->tenant);
    foreach (['Havnelys Studio', 'Shopify'] as $name) {
        for ($i = 0; $i < 3; $i++) {
            $this->w->answer($this->w->p1, '2026-11-18 10:00:00', ['brand' => ['pos' => 1], 'unknown' => $name]);
        }
    }
});

test('the page lists the queue with suggestions, the competitors in color order and the decided names', function () {
    EntityDecision::query()->create([
        'brand_id' => $this->w->brand->id, 'name_key' => 'netthavn', 'display_name' => 'Netthavn', 'variants' => ['Netthavn'],
        'decision' => 'ignored', 'alias_ids' => [], 'mention_ids' => [], 'decided_by' => $this->w->tenant->owner->id, 'decided_at' => now(),
    ]);
    $order = Competitor::query()->where('brand_id', $this->w->brand->id)->inColorOrder()->pluck('id')->all();

    $this->actingAs($this->w->tenant->owner)->get(route('brands.setup.competitors', $this->w->brand))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Brands/Setup/Competitors')
            ->where('tab', 'pending')
            ->has('queue', 2)
            ->where('queue.0.answers', 3)
            ->where('queue', fn ($queue) => collect($queue)->firstWhere('name', 'Shopify')['suggestion']['decision'] === 'other')
            ->where('competitors', fn ($rows) => collect($rows)->pluck('id')->all() === $order)
            ->has('ignored', 1)
            ->where('ignored.0.undoable', true)
            ->where('canManage', true));
});

test('a decided name leaves the queue and the sidebar count', function () {
    $owner = $this->w->tenant->owner;
    $this->actingAs($owner)->postJson(route('brands.entities.decide', $this->w->brand), ['keys' => ['shopify'], 'decision' => 'other'])->assertOk();

    $this->get(route('brands.setup.competitors', $this->w->brand))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('queue', 1)->has('others', 1)->where('others.0.name', 'Shopify'));
    expect(WorkspaceCounts::for($this->w->tenant, $this->w->brand)['entities_pending'])->toBe(1);
});

test('a reader sees the page but cannot manage', function () {
    $reader = User::factory()->create();
    $this->w->tenant->users()->attach($reader, ['role' => 'reader']);
    $reader->switchTeam($this->w->tenant);

    $this->actingAs($reader)->get(route('brands.setup.competitors', $this->w->brand))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));
});

test('an unknown tab falls back to the queue and a foreign market is a 404', function () {
    $this->actingAs($this->w->tenant->owner);
    $this->get(route('brands.setup.competitors', [$this->w->brand, 'tab' => 'nonsense']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tab', 'pending'));
    $this->get(route('brands.setup.competitors', [$this->w->brand, 'market' => 999999]))->assertNotFound();
});

test('the competitor analysis page links to the queue instead of listing suggestions', function () {
    $this->actingAs($this->w->tenant->owner)->get(route('brands.competitors.index', $this->w->brand))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('pendingEntities', 2)->missing('suggestions'));
});
