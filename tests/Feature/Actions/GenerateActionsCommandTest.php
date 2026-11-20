<?php

// T43: ekko:generate-actions runs one job per active tenant, each in its own context, so an action is built
// from its own tenant's figures only.

use App\Enums\ActionType;
use App\Models\Action;
use App\Models\BotHitDaily;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Tests\Support\Metrics\MetricsWorld;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00:00', 'Europe/Oslo'));
    config(['services.anthropic.key' => '']);
});

test('each tenant gets actions from its own data only', function () {
    $a = MetricsWorld::create();
    BotHitDaily::factory()->create(['brand_id' => $a->brand->id, 'bot' => 'gptbot', 'date' => '2026-11-14', 'hits' => 40]);
    $b = MetricsWorld::create();
    // Tenant B's bots fetch everything: no crawler action for B, and A's data must not leak into B.
    foreach (['gptbot', 'oai_searchbot', 'perplexitybot', 'claude_searchbot'] as $bot) {
        BotHitDaily::factory()->create(['brand_id' => $b->brand->id, 'bot' => $bot, 'date' => '2026-11-14', 'hits' => 9]);
    }

    $this->artisan('ekko:generate-actions', ['--sync' => true])->assertSuccessful();

    $context = app(TenantContext::class);
    $context->set($a->tenant->id);
    expect(Action::query()->where('type', ActionType::CrawlerAccess->value)->pluck('evidence')->map(fn ($e) => $e['bot'])->sort()->values()->all())
        ->toBe(['claude_searchbot', 'oai_searchbot', 'perplexitybot'])
        ->and(Action::query()->pluck('evidence')->map(fn ($e) => $e['other_bot_hits'])->unique()->values()->all())->toBe([40]);

    $context->set($b->tenant->id);
    expect(Action::query()->count())->toBe(0);
});

test('a second run the same week is skipped unless forced', function () {
    $a = MetricsWorld::create();
    BotHitDaily::factory()->create(['brand_id' => $a->brand->id, 'bot' => 'gptbot', 'date' => '2026-11-14', 'hits' => 40]);

    $this->artisan('ekko:generate-actions', ['--sync' => true, '--tenant' => [$a->tenant->id]])->assertSuccessful();
    BotHitDaily::factory()->create(['brand_id' => $a->brand->id, 'bot' => 'gptbot', 'date' => '2026-11-15', 'hits' => 2]);
    $this->artisan('ekko:generate-actions', ['--sync' => true, '--tenant' => [$a->tenant->id]])->assertSuccessful();

    app(TenantContext::class)->set($a->tenant->id);
    expect(Action::query()->first()->evidence['other_bot_hits'])->toBe(40);

    $this->artisan('ekko:generate-actions', ['--sync' => true, '--force' => true, '--tenant' => [$a->tenant->id]])->assertSuccessful();
    expect(Action::query()->count())->toBe(3)->and(Action::query()->first()->evidence['other_bot_hits'])->toBe(42);
});

test('an active pitch is refreshed daily and an expired pitch is left alone', function () {
    $pitch = MetricsWorld::create();
    $pitch->tenant->forceFill(['kind' => 'pitch', 'pitch_expires_at' => now()->addDays(3)])->save();
    BotHitDaily::factory()->create(['brand_id' => $pitch->brand->id, 'bot' => 'gptbot', 'date' => '2026-11-14', 'hits' => 40]);
    $expired = MetricsWorld::create();
    $expired->tenant->forceFill(['kind' => 'pitch', 'pitch_expires_at' => now()->subMinute()])->save();
    BotHitDaily::factory()->create(['brand_id' => $expired->brand->id, 'bot' => 'gptbot', 'date' => '2026-11-14', 'hits' => 40]);
    $customer = MetricsWorld::create();
    BotHitDaily::factory()->create(['brand_id' => $customer->brand->id, 'bot' => 'gptbot', 'date' => '2026-11-14', 'hits' => 40]);

    $this->artisan('ekko:generate-actions', ['--pitches' => true, '--sync' => true])->assertSuccessful();

    $context = app(TenantContext::class);
    $context->set($pitch->tenant->id);
    expect(Action::query()->where('type', ActionType::CrawlerAccess->value)->count())->toBe(3);
    $context->set($expired->tenant->id);
    expect(Action::query()->count())->toBe(0);
    $context->set($customer->tenant->id);
    expect(Action::query()->count())->toBe(0);

    $context->set($pitch->tenant->id);
    BotHitDaily::factory()->create(['brand_id' => $pitch->brand->id, 'bot' => 'gptbot', 'date' => '2026-11-15', 'hits' => 2]);
    $this->artisan('ekko:generate-actions', ['--pitches' => true, '--sync' => true])->assertSuccessful();
    $context->set($pitch->tenant->id);
    expect(Action::query()->first()->evidence['other_bot_hits'])->toBe(42);
});
